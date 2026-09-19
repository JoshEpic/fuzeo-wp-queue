<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\WordPress;

use Fuzeo\Queue\Execution\CompatTrigger;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;
use PHPUnit\Framework\TestCase;

final class Phase12WordPressTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('ABSPATH') || !function_exists('wp_schedule_event')) {
            self::markTestSkipped('WP_TESTS_DIR is not configured.');
        }
        Coordinator::bootForTesting(['driver' => 'memory', 'compatibility_enabled' => false]);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        ProcessOrderHandler::reset();
        wp_clear_scheduled_hook(CompatTrigger::HOOK);
    }

    protected function tearDown(): void
    {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(CompatTrigger::HOOK);
        }
        Coordinator::reset();
    }

    public function testEnableRegistersSingleNetworkTick(): void
    {
        Queue::execution()->enable();
        CompatTrigger::reconcile(Coordinator::get(), Queue::execution()->state());
        self::assertNotFalse(wp_next_scheduled(CompatTrigger::HOOK));
        Queue::execution()->disable();
        self::assertFalse(wp_next_scheduled(CompatTrigger::HOOK));
    }

    public function testTickHookProcessesQueueJobs(): void
    {
        Queue::dispatch(new ProcessOrderJob(4));
        Queue::execution()->enable();
        CompatTrigger::handle();
        self::assertSame(1, ProcessOrderHandler::$handled);
    }

    public function testDisableWpCronIsReported(): void
    {
        $snap = Queue::execution()->snapshot();
        self::assertSame(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON, $snap['wp_cron_automatic_spawning_disabled']);
    }
}
