<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\WordPress;

use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\Handler;
use Fuzeo\Queue\Jobs\Job;
use Fuzeo\Queue\Jobs\JobState;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\WordPress\WordPressBootstrap;
use Fuzeo\Queue\Worker\WorkerLoop;
use Fuzeo\Queue\Worker\WorkerOptions;
use PHPUnit\Framework\TestCase;

final class WordPressCoreTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('ABSPATH') || !function_exists('wp_insert_user')) {
            self::markTestSkipped('WP_TESTS_DIR is not configured. Install the WordPress test suite to enable these tests.');
        }
        Coordinator::bootForTesting(['driver' => 'memory']);
        WordPressBootstrap::register();
        WpObserveBlogHandler::$blogId = 0;
        WpObserveUserHandler::$userId = -1;
    }

    protected function tearDown(): void
    {
        Coordinator::reset();
        if (function_exists('restore_current_blog')) {
            $guard = 16;
            while ($guard-- > 0 && !empty($GLOBALS['switched'])) {
                restore_current_blog();
            }
        }
        if (function_exists('wp_set_current_user')) {
            wp_set_current_user(0);
        }
    }

    public function testNestedSwitchToBlogIsRestoredForNextJob(): void
    {
        if (!is_multisite()) {
            self::markTestSkipped('Multisite is required.');
        }
        $siteA = self::factorySite();
        $siteB = self::factorySite();
        $baseline = get_current_blog_id();
        Queue::register(WpLeakBlogJob::class, new Origin('acme/shop', '1.0.0'), WpLeakBlogHandler::class);
        Queue::register(WpObserveBlogJob::class, new Origin('acme/shop', '1.0.0'), WpObserveBlogHandler::class);
        WpLeakBlogHandler::$target = $siteB;
        Queue::on('default')->onSite(1, $siteA)->dispatch(new WpLeakBlogJob());
        Queue::on('default')->onSite(1, $siteA)->dispatch(new WpObserveBlogJob());
        $this->worker(2)->run();
        self::assertSame($baseline, get_current_blog_id());
        self::assertSame($siteA, WpObserveBlogHandler::$blogId);
    }

    public function testDeletedSiteFailsClosed(): void
    {
        if (!is_multisite() || !function_exists('wp_delete_site')) {
            self::markTestSkipped('Multisite site deletion is required.');
        }
        $site = self::factorySite();
        Queue::register(WpObserveBlogJob::class, new Origin('acme/shop', '1.0.0'), WpObserveBlogHandler::class);
        Queue::on('default')->onSite(1, $site)->dispatch(new WpObserveBlogJob());
        wp_delete_site($site);
        WpObserveBlogHandler::$blogId = 0;
        $this->worker(1)->run();
        self::assertSame(0, WpObserveBlogHandler::$blogId);
        $driver = Coordinator::get()->driver();
        self::assertSame(JobState::Dead, $driver->all()[0]->state);
    }

    public function testSiteIdSurvivesDomainChange(): void
    {
        if (!is_multisite() || !function_exists('update_blog_details')) {
            self::markTestSkipped('Multisite domain updates are required.');
        }
        $site = self::factorySite();
        Queue::register(WpObserveBlogJob::class, new Origin('acme/shop', '1.0.0'), WpObserveBlogHandler::class);
        Queue::on('default')->onSite(1, $site)->dispatch(new WpObserveBlogJob());
        update_blog_details($site, ['domain' => 'renamed.example.org']);
        $this->worker(1)->run();
        self::assertSame($site, WpObserveBlogHandler::$blogId);
    }

    public function testCurrentUserDoesNotLeak(): void
    {
        $login = 'fuzeo' . wp_generate_password(8, false);
        $user = wp_insert_user([
            'user_login' => $login,
            'user_pass' => wp_generate_password(12),
            'user_email' => $login . '@example.org',
        ]);
        self::assertIsInt($user);
        Queue::register(WpLeakUserJob::class, new Origin('acme/shop', '1.0.0'), WpLeakUserHandler::class);
        Queue::register(WpObserveUserJob::class, new Origin('acme/shop', '1.0.0'), WpObserveUserHandler::class);
        WpLeakUserHandler::$userId = (int) $user;
        Queue::dispatch(new WpLeakUserJob());
        Queue::dispatch(new WpObserveUserJob());
        $this->worker(2)->run();
        self::assertSame(0, WpObserveUserHandler::$userId);
    }

    public function testArchivedSiteIsNotExecutable(): void
    {
        if (!is_multisite() || !function_exists('update_blog_status')) {
            self::markTestSkipped('Multisite status updates are required.');
        }
        $site = self::factorySite();
        update_blog_status($site, 'archived', '1');
        Queue::register(WpObserveBlogJob::class, new Origin('acme/shop', '1.0.0'), WpObserveBlogHandler::class);
        Queue::on('default')->onSite(1, $site)->dispatch(new WpObserveBlogJob());
        WpObserveBlogHandler::$blogId = 0;
        $this->worker(1)->run();
        self::assertSame(0, WpObserveBlogHandler::$blogId);
    }

    public function testSiteHealthRegisters(): void
    {
        $tests = apply_filters('site_status_tests', ['direct' => []]);
        self::assertIsArray($tests['direct'] ?? null);
        self::assertArrayHasKey('fuzeo_queue_driver', $tests['direct']);
    }

    private function worker(int $maxJobs): WorkerLoop
    {
        return WorkerLoop::fromManager(Coordinator::get(), new WorkerOptions(sleepSeconds: 0, maxJobs: $maxJobs));
    }

    private static function factorySite(): int
    {
        $id = wp_insert_site([
            'domain' => 'site' . wp_generate_password(6, false) . '.example.org',
            'path' => '/',
            'title' => 'Fuzeo site',
        ]);
        if (is_wp_error($id)) {
            self::fail($id->get_error_message());
        }

        return (int) $id;
    }
}

final class WpLeakBlogJob implements Job
{
    public static function type(): string
    {
        return 'wp.leak_blog';
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

final class WpLeakBlogHandler implements Handler
{
    public static int $target = 0;

    public function handle(Envelope $envelope): void
    {
        unset($envelope);
        if (self::$target > 0) {
            switch_to_blog(self::$target);
        }
    }
}

final class WpObserveBlogJob implements Job
{
    public static function type(): string
    {
        return 'wp.observe_blog';
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

final class WpObserveBlogHandler implements Handler
{
    public static int $blogId = 0;

    public function handle(Envelope $envelope): void
    {
        unset($envelope);
        self::$blogId = (int) get_current_blog_id();
    }
}

final class WpLeakUserJob implements Job
{
    public static function type(): string
    {
        return 'wp.leak_user';
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

final class WpLeakUserHandler implements Handler
{
    public static int $userId = 0;

    public function handle(Envelope $envelope): void
    {
        unset($envelope);
        wp_set_current_user(self::$userId);
    }
}

final class WpObserveUserJob implements Job
{
    public static function type(): string
    {
        return 'wp.observe_user';
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

final class WpObserveUserHandler implements Handler
{
    public static int $userId = -1;

    public function handle(Envelope $envelope): void
    {
        unset($envelope);
        self::$userId = (int) get_current_user_id();
    }
}
