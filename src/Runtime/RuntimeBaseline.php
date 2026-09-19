<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Runtime;

/**
 * Process-local snapshot captured once after WordPress and Fuzeo Queue boot.
 * This is not a dump of PHP memory.
 */
final class RuntimeBaseline
{
    /**
     * @param array<string, mixed> $get
     * @param array<string, mixed> $post
     * @param array<string, mixed> $request
     * @param array<string, mixed> $cookie
     * @param array<string, mixed> $files
     * @param array<string, mixed> $server
     */
    public function __construct(
        public readonly int $blogId,
        public readonly int $userId,
        public readonly string $locale,
        public readonly int $outputBufferLevel,
        public readonly string $workingDirectory,
        public readonly int $memoryBytes,
        public readonly int $switchedStackDepth,
        public readonly mixed $errorHandler,
        public readonly mixed $exceptionHandler,
        public readonly array $get,
        public readonly array $post,
        public readonly array $request,
        public readonly array $cookie,
        public readonly array $files,
        public readonly array $server,
        public readonly string $generation,
    ) {
    }

    public static function capture(WordPressRuntime $wp, string $generation): self
    {
        $error = set_error_handler(static fn (): bool => false);
        if ($error !== null) {
            restore_error_handler();
            set_error_handler($error);
        } else {
            restore_error_handler();
        }
        $exception = set_exception_handler(static function (\Throwable $e): void {
            throw $e;
        });
        if ($exception !== null) {
            restore_exception_handler();
            set_exception_handler($exception);
        } else {
            restore_exception_handler();
        }

        return new self(
            blogId: $wp->currentBlogId(),
            userId: $wp->currentUserId(),
            locale: $wp->currentLocale(),
            outputBufferLevel: ob_get_level(),
            workingDirectory: (string) getcwd(),
            memoryBytes: memory_get_usage(true),
            switchedStackDepth: $wp->switchedStackDepth(),
            errorHandler: $error,
            exceptionHandler: $exception,
            get: $_GET,
            post: $_POST,
            request: $_REQUEST,
            cookie: $_COOKIE,
            files: $_FILES,
            server: $_SERVER,
            generation: $generation,
        );
    }
}
