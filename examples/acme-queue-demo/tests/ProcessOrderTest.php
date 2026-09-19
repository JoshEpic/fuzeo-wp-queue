<?php

declare(strict_types=1);

use Acme\QueueDemo\ProcessOrder;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use PHPUnit\Framework\TestCase;

final class ProcessOrderTest extends TestCase
{
    protected function tearDown(): void
    {
        Coordinator::reset();
    }

    public function testDispatchIsAssertableWithoutMysql(): void
    {
        Coordinator::bootForTesting();
        Queue::register(ProcessOrder::class, new Origin('acme/queue-demo', '1.0.0'));
        Queue::fake();
        Queue::dispatch(new ProcessOrder(123));
        Queue::assertDispatched(ProcessOrder::class);
        Queue::assertDispatchedTimes(ProcessOrder::class, 1);
        Queue::fake()->assertDispatchedWithPayload(ProcessOrder::class, ['order_id' => 123]);
    }
}
