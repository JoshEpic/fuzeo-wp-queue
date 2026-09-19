<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Unit;

use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;
use PHPUnit\Framework\TestCase;

final class FakeQueueTest extends TestCase
{
    protected function setUp(): void
    {
        Coordinator::bootForTesting();
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Coordinator::reset();
    }

    public function testAssertsDispatchTypeQueuePayloadOriginAndSite(): void
    {
        Queue::on('fulfillment')
            ->onSite(2, 42)
            ->dispatch(new ProcessOrderJob(99));

        Queue::assertDispatched(ProcessOrderJob::class);
        Queue::assertDispatchedTimes('acme.process_order', 1);
        $fake = Queue::fake();
        $fake->assertDispatchedOn('fulfillment', ProcessOrderJob::class);
        $fake->assertDispatchedWithPayload(ProcessOrderJob::class, ['order_id' => 99]);
        $fake->assertDispatchedForSite(ProcessOrderJob::class, 42, 2);
        $fake->assertDispatchedFrom(ProcessOrderJob::class, 'acme/shop');
        $envelope = $fake->dispatched(ProcessOrderJob::class)[0];
        self::assertSame('fulfillment', $envelope->queue);
        self::assertSame(99, $envelope->payload['order_id']);
        self::assertSame(42, $envelope->context->siteId);
        self::assertSame('acme/shop', $envelope->origin->package);
    }

    public function testAssertNothingDispatched(): void
    {
        Queue::assertNothingDispatched();
        Queue::assertNotDispatched(ProcessOrderJob::class);
        self::assertSame([], Queue::fake()->dispatched());
    }
}
