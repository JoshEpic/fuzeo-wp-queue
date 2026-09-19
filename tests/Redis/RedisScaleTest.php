<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Redis;

use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Orchestration\BatchState;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;
use Fuzeo\Queue\Worker\WorkerLoop;
use Fuzeo\Queue\Worker\WorkerOptions;

final class RedisScaleTest extends RedisTestCase
{
    protected function tearDown(): void
    {
        Coordinator::reset();
        parent::tearDown();
    }

    public function testTenThousandMemberBatchCompletesWithoutMemberScanCliff(): void
    {
        if (getenv('FUZEO_QUEUE_SCALE_TESTS') !== '1') {
            self::markTestSkipped('Set FUZEO_QUEUE_SCALE_TESTS=1 to run the 10k Redis batch release gate.');
        }
        Coordinator::bootForTesting(['driver' => 'redis'], $this->redisDriver, clock: $this->clock);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        $jobs = [];
        for ($i = 0; $i < 10000; $i++) {
            $jobs[] = new ProcessOrderJob($i);
        }
        $started = hrtime(true);
        $batch = Queue::batch($jobs)->dispatch();
        WorkerLoop::fromManager(Coordinator::get(), new WorkerOptions(sleepSeconds: 0, maxJobs: 0))->run(10050);
        $ms = (hrtime(true) - $started) / 1e6;
        $fresh = Coordinator::get()->orchestrator()->store()->getBatch($batch->batchId);
        self::assertNotNull($fresh);
        self::assertSame(BatchState::Completed, $fresh->state);
        self::assertSame(10000, $fresh->completedJobs);
        self::assertLessThan(180000, $ms);
    }
}
