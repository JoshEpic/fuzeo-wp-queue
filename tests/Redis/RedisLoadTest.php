<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Redis;

use Fuzeo\Queue\Drivers\ReserveRequest;
use Fuzeo\Queue\Jobs\JobState;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;

final class RedisLoadTest extends RedisTestCase
{
    protected function tearDown(): void
    {
        Coordinator::reset();
        parent::tearDown();
    }

    public function testDrainCompletesEveryJob(): void
    {
        Coordinator::bootForTesting(['driver' => 'redis'], $this->redisDriver, clock: $this->clock);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        $n = 200;
        $ids = [];
        $started = microtime(true);
        for ($i = 0; $i < $n; $i++) {
            $ids[] = Queue::dispatch(new ProcessOrderJob($i))->jobId;
        }
        $enqueued = microtime(true) - $started;
        $reserved = 0;
        $loop = microtime(true);
        while ($job = $this->redisDriver->reserve(new ReserveRequest('default', 'drain', 30))) {
            $this->redisDriver->acknowledge($job);
            $reserved++;
        }
        $drained = microtime(true) - $loop;
        self::assertSame($n, $reserved);
        foreach ($ids as $id) {
            self::assertSame(JobState::Completed, $this->redisDriver->job($id)->state);
        }
        self::assertGreaterThan(0.0, $enqueued + $drained);
        $counts = $this->redisDriver->countsByState();
        self::assertSame(0, $counts['pending']);
        self::assertSame(0, $counts['reserved']);
        self::assertSame($n, $counts['completed']);
    }
}
