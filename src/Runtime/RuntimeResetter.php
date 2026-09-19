<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Runtime;

use Fuzeo\Queue\Persistence\Connection;
use Fuzeo\Queue\Persistence\PdoConnection;
use Fuzeo\Queue\Redis\RedisClient;

/**
 * Restores known process-local state between jobs. Does not snapshot all PHP memory,
 * unload classes, unregister shutdown functions, or flush the shared object cache.
 */
final class RuntimeResetter
{
    public function __construct(
        private readonly RuntimeBaseline $baseline,
        private readonly WordPressRuntime $wp,
        private readonly RuntimeLogger $logger = new NullLogger(),
        private readonly ?Connection $connection = null,
        private readonly ?RedisClient $redis = null,
    ) {
    }

    public function prepare(): void
    {
        $this->restoreRequestGlobals();
    }

    public function reset(): ResetResult
    {
        $actions = [];
        $recycle = null;
        $message = '';

        $this->cleanOutputBuffers();
        $actions[] = 'output_buffers';

        $this->restoreHandlers();
        $actions[] = 'php_handlers';

        $this->restoreWorkingDirectory();
        $actions[] = 'cwd';

        $this->closeSession();
        $actions[] = 'session';

        $this->restoreRequestGlobals();
        $actions[] = 'request_globals';

        $this->wp->resetQuery();
        $actions[] = 'query';

        if ($this->wp->currentUserId() !== $this->baseline->userId) {
            $this->wp->setCurrentUser($this->baseline->userId);
            $actions[] = 'current_user';
        }

        if ($this->wp->currentLocale() !== $this->baseline->locale) {
            $this->wp->restoreLocale();
            $actions[] = 'locale';
        }

        $this->wp->restoreBlog();
        if ($this->wp->currentBlogId() !== $this->baseline->blogId) {
            $this->wp->switchToBlog($this->baseline->blogId);
            $this->wp->restoreBlog();
            $this->logger->log('runtime.blog_context_recovered', [
                'expected' => $this->baseline->blogId,
                'actual' => $this->wp->currentBlogId(),
            ]);
            $actions[] = 'blog_recovered';
        }
        if ($this->wp->currentBlogId() !== $this->baseline->blogId || $this->wp->switchedStackDepth() !== $this->baseline->switchedStackDepth) {
            $recycle = RecycleReason::ContextCorruption;
            $message = 'Blog context could not be restored to the worker baseline.';
        }

        $this->wp->flushRuntimeCache();
        $actions[] = 'runtime_cache';

        $open = $this->unexpectedTransaction();
        if ($open) {
            $this->rollbackTransaction();
            $this->logger->log('runtime.transaction_rolled_back', []);
            $actions[] = 'transaction_rollback';
            $recycle = RecycleReason::TransactionLeak;
            $message = 'An unexpected SQL transaction was rolled back at the job boundary.';
        }

        $this->discardRedisTransaction();

        return $recycle === null
            ? new ResetResult(true, $actions)
            : ResetResult::recycle($recycle, $message, ...$actions);
    }

    public function assertBaseline(): bool
    {
        return $this->wp->currentBlogId() === $this->baseline->blogId
            && $this->wp->switchedStackDepth() === $this->baseline->switchedStackDepth
            && $this->wp->currentUserId() === $this->baseline->userId
            && ob_get_level() <= $this->baseline->outputBufferLevel;
    }

    public function baseline(): RuntimeBaseline
    {
        return $this->baseline;
    }

    private function cleanOutputBuffers(): void
    {
        while (ob_get_level() > $this->baseline->outputBufferLevel) {
            ob_end_clean();
        }
    }

    private function restoreHandlers(): void
    {
        $guard = 16;
        while ($guard-- > 0) {
            $current = set_error_handler(static fn (): bool => false);
            restore_error_handler();
            if ($current == $this->baseline->errorHandler) { // phpcs:ignore
                if ($current !== null) {
                    restore_error_handler();
                    set_error_handler($current);
                }
                break;
            }
            restore_error_handler();
        }
        $guard = 16;
        while ($guard-- > 0) {
            $current = set_exception_handler(static function (\Throwable $e): void {
                throw $e;
            });
            restore_exception_handler();
            if ($current == $this->baseline->exceptionHandler) { // phpcs:ignore
                if ($current !== null) {
                    restore_exception_handler();
                    set_exception_handler($current);
                }
                break;
            }
            restore_exception_handler();
        }
    }

    private function restoreWorkingDirectory(): void
    {
        $cwd = getcwd();
        if (is_string($cwd) && $cwd !== $this->baseline->workingDirectory && is_dir($this->baseline->workingDirectory)) {
            chdir($this->baseline->workingDirectory);
        }
    }

    private function closeSession(): void
    {
        if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    private function restoreRequestGlobals(): void
    {
        $_GET = $this->baseline->get;
        $_POST = $this->baseline->post;
        $_REQUEST = $this->baseline->request;
        $_COOKIE = $this->baseline->cookie;
        $_FILES = $this->baseline->files;
        foreach (['HTTP_HOST', 'REQUEST_URI', 'QUERY_STRING', 'REQUEST_METHOD'] as $key) {
            if (array_key_exists($key, $this->baseline->server)) {
                $_SERVER[$key] = $this->baseline->server[$key];
            } else {
                unset($_SERVER[$key]);
            }
        }
    }

    private function unexpectedTransaction(): bool
    {
        if ($this->connection instanceof PdoConnection && $this->connection->inTransaction()) {
            return true;
        }

        return $this->wp->inTransaction();
    }

    private function rollbackTransaction(): void
    {
        if ($this->connection instanceof PdoConnection && $this->connection->inTransaction()) {
            $this->connection->rollBack();
        }
        $this->wp->rollbackOpenTransaction();
    }

    private function discardRedisTransaction(): void
    {
        if ($this->redis === null) {
            return;
        }
        try {
            $this->redis->command('DISCARD', []);
        } catch (\Throwable) {
        }
    }
}
