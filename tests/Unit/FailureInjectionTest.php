<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Unit;

use Fuzeo\Queue\Drivers\Memory\MemoryDriver;
use Fuzeo\Queue\Drivers\ReserveRequest;
use Fuzeo\Queue\Jobs\JobState;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Metrics\IsolatingRecorder;
use Fuzeo\Queue\Metrics\MetricDimensions;
use Fuzeo\Queue\Metrics\MetricName;
use Fuzeo\Queue\Metrics\MetricRecorder;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;
use Fuzeo\Queue\Worker\WorkerLoop;
use Fuzeo\Queue\Worker\WorkerOptions;
use PHPUnit\Framework\TestCase;

final class FailureInjectionTest extends TestCase
{
    protected function tearDown(): void
    {
        Coordinator::reset();
        parent::tearDown();
    }

    public function testMetricsFailureDoesNotFailTheJob(): void
    {
        Coordinator::bootForTesting();
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        $throwing = new class implements MetricRecorder {
            public function increment(string $metric, int $amount = 1, ?MetricDimensions $dimensions = null): void
            {
                throw new \RuntimeException('metrics down');
            }

            public function observe(string $metric, float $value, ?MetricDimensions $dimensions = null): void
            {
                throw new \RuntimeException('metrics down');
            }

            public function isDegraded(): bool
            {
                return false;
            }
        };
        $isolated = new IsolatingRecorder($throwing);
        $isolated->increment(MetricName::JOBS_DISPATCHED, 1, null);
        self::assertTrue($isolated->isDegraded());
        Queue::dispatch(new ProcessOrderJob(1));
        WorkerLoop::fromManager(Coordinator::get(), new WorkerOptions(sleepSeconds: 0, maxJobs: 1))->run();
        $driver = Coordinator::get()->driver();
        self::assertInstanceOf(MemoryDriver::class, $driver);
        self::assertSame(JobState::Completed, $driver->all()[0]->state);
    }

    public function testAckAfterHandlerLeavesCompletedState(): void
    {
        Coordinator::bootForTesting();
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        $envelope = Queue::dispatch(new ProcessOrderJob(2));
        $driver = Coordinator::get()->driver();
        self::assertInstanceOf(MemoryDriver::class, $driver);
        $reserved = $driver->reserve(new ReserveRequest('default', 'w1', 30));
        self::assertNotNull($reserved);
        $driver->acknowledge($reserved);
        self::assertSame(JobState::Completed, $driver->job($envelope->jobId)->state);
    }
}
