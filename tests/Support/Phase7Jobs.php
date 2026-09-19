<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Support;

use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\Handler;
use Fuzeo\Queue\Jobs\Job;
use Fuzeo\Queue\Runtime\FakeWordPressRuntime;

final class LeakUserJob implements Job
{
    public static function type(): string
    {
        return 'test.leak_user';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }

    public function payload(): array
    {
        return [];
    }
}

final class LeakUserHandler implements Handler
{
    public function handle(Envelope $envelope): void
    {
        unset($envelope);
        $wp = $GLOBALS['fuzeo_test_wp'] ?? null;
        if ($wp instanceof FakeWordPressRuntime) {
            $wp->setCurrentUser(123);
        }
        if (function_exists('wp_set_current_user')) {
            wp_set_current_user(123);
        }
    }
}

final class ObserveUserJob implements Job
{
    public static function type(): string
    {
        return 'test.observe_user';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }

    public function payload(): array
    {
        return [];
    }
}

final class ObserveUserHandler implements Handler
{
    public static int $userId = -1;

    public function handle(Envelope $envelope): void
    {
        unset($envelope);
        $wp = $GLOBALS['fuzeo_test_wp'] ?? null;
        self::$userId = $wp instanceof FakeWordPressRuntime
            ? $wp->currentUserId()
            : (function_exists('get_current_user_id') ? (int) get_current_user_id() : 0);
    }
}

final class LeakBlogJob implements Job
{
    public static function type(): string
    {
        return 'test.leak_blog';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }

    public function payload(): array
    {
        return [];
    }
}

final class LeakBlogHandler implements Handler
{
    public function handle(Envelope $envelope): void
    {
        unset($envelope);
        $wp = $GLOBALS['fuzeo_test_wp'] ?? null;
        if ($wp instanceof FakeWordPressRuntime) {
            $wp->switchToBlog(3);
        }
    }
}

final class LeakLocaleJob implements Job
{
    public static function type(): string
    {
        return 'test.leak_locale';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }

    public function payload(): array
    {
        return [];
    }
}

final class LeakLocaleHandler implements Handler
{
    public function handle(Envelope $envelope): void
    {
        unset($envelope);
        $wp = $GLOBALS['fuzeo_test_wp'] ?? null;
        if ($wp instanceof FakeWordPressRuntime) {
            $wp->locale = 'fr_FR';
        }
    }
}

final class ObserveLocaleJob implements Job
{
    public static function type(): string
    {
        return 'test.observe_locale';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }

    public function payload(): array
    {
        return [];
    }
}

final class ObserveLocaleHandler implements Handler
{
    public static string $locale = '';

    public function handle(Envelope $envelope): void
    {
        unset($envelope);
        $wp = $GLOBALS['fuzeo_test_wp'] ?? null;
        self::$locale = $wp instanceof FakeWordPressRuntime ? $wp->currentLocale() : '';
    }
}

final class LeakBufferJob implements Job
{
    public static function type(): string
    {
        return 'test.leak_buffer';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }

    public function payload(): array
    {
        return [];
    }
}

final class LeakBufferHandler implements Handler
{
    public function handle(Envelope $envelope): void
    {
        unset($envelope);
        ob_start();
        ob_start();
        throw new \RuntimeException('buffer leak');
    }
}

final class LeakTransactionJob implements Job
{
    public static function type(): string
    {
        return 'test.leak_tx';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }

    public function payload(): array
    {
        return [];
    }
}

final class LeakTransactionHandler implements Handler
{
    public function handle(Envelope $envelope): void
    {
        unset($envelope);
        $wp = $GLOBALS['fuzeo_test_wp'] ?? null;
        if ($wp instanceof FakeWordPressRuntime) {
            $wp->transactionOpen = true;
        }
        throw new \RuntimeException('open transaction');
    }
}

final class MemoryLeakJob implements Job
{
    public static function type(): string
    {
        return 'test.memory_leak';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }

    public function payload(): array
    {
        return [];
    }
}

final class MemoryLeakHandler implements Handler
{
    /** @var list<string> */
    public static array $leak = [];

    public function handle(Envelope $envelope): void
    {
        unset($envelope);
        self::$leak[] = str_repeat('x', 1024 * 1024);
    }
}

final class ObserveBlogJob implements Job
{
    public static function type(): string
    {
        return 'test.observe_blog';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }

    public function payload(): array
    {
        return [];
    }
}

final class ObserveBlogHandler implements Handler
{
    public static int $blogId = 0;

    public function handle(Envelope $envelope): void
    {
        unset($envelope);
        $wp = $GLOBALS['fuzeo_test_wp'] ?? null;
        self::$blogId = $wp instanceof FakeWordPressRuntime ? $wp->currentBlogId() : 0;
    }
}

final class ChdirJob implements Job
{
    public static function type(): string
    {
        return 'test.chdir';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }

    public function payload(): array
    {
        return [];
    }
}

final class ChdirHandler implements Handler
{
    public function handle(Envelope $envelope): void
    {
        unset($envelope);
        chdir(sys_get_temp_dir());
        throw new \RuntimeException('chdir');
    }
}

final class InactiveOriginJob implements Job
{
    public static function type(): string
    {
        return 'test.inactive_origin';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }

    public function payload(): array
    {
        return [];
    }
}

final class InactiveOriginHandler implements Handler
{
    public static int $handled = 0;

    public function handle(Envelope $envelope): void
    {
        unset($envelope);
        self::$handled++;
    }
}
