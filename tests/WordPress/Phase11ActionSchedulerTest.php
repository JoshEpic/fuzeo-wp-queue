<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\WordPress;

use Fuzeo\Queue\Interop\FallbackEnvelope;
use Fuzeo\Queue\Interop\NativeActionSchedulerGateway;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;
use PHPUnit\Framework\TestCase;

final class Phase11ActionSchedulerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('as_enqueue_async_action')) {
            self::markTestSkipped('Action Scheduler is not installed.');
        }
        Coordinator::bootForTesting(['driver' => 'memory']);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
    }

    protected function tearDown(): void
    {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(FallbackEnvelope::HOOK);
        }
        Coordinator::reset();
    }

    public function testDetectAndFallbackDispatch(): void
    {
        $gateway = new NativeActionSchedulerGateway();
        self::assertTrue($gateway->detected());
        Coordinator::reset();
        Coordinator::bootForTesting(['driver' => 'memory'], new \Fuzeo\Queue\Drivers\UnavailableDriver());
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        $runtime = Coordinator::get()->interop()->runtime(new Origin('acme/shop', '1.0.0'));
        self::assertSame('action_scheduler', $runtime->name()->value);
        $runtime->dispatch(new ProcessOrderJob(4));
        self::assertGreaterThan(0, $gateway->count(['hook' => FallbackEnvelope::HOOK, 'status' => 'pending']));
    }
}
