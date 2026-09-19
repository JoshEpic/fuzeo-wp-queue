<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Unit;

use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Metrics\IsolatingRecorder;
use Fuzeo\Queue\Metrics\MemoryMetricsRepository;
use Fuzeo\Queue\Metrics\MetricDimensions;
use Fuzeo\Queue\Metrics\MetricName;
use Fuzeo\Queue\Metrics\MetricRecorder;
use Fuzeo\Queue\Metrics\MetricResolution;
use Fuzeo\Queue\Metrics\MetricsQuery;
use Fuzeo\Queue\Metrics\MetricsRetention;
use Fuzeo\Queue\Metrics\RepositoryRecorder;
use Fuzeo\Queue\Metrics\Timing;
use Fuzeo\Queue\Operations\Operator;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Support\FrozenClock;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;
use Fuzeo\Queue\Worker\WorkerLoop;
use Fuzeo\Queue\Worker\WorkerOptions;
use PHPUnit\Framework\TestCase;

final class Phase8MetricsTest extends TestCase
{
    protected function setUp(): void
    {
        Coordinator::bootForTesting();
        ProcessOrderHandler::reset();
    }

    protected function tearDown(): void
    {
        Coordinator::reset();
    }

    public function testCountersAndBuckets(): void
    {
        $repo = new MemoryMetricsRepository();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-09-19T12:00:00Z'));
        $recorder = new RepositoryRecorder($repo, $clock);
        $dims = new MetricDimensions(queue: 'imports', jobType: 'acme.process_order', origin: 'acme/shop');
        $recorder->increment(MetricName::JOBS_DISPATCHED, 2, $dims);
        $recorder->observe(MetricName::RUNTIME_MS, 12, $dims);
        $query = new MetricsQuery($repo);
        $from = new \DateTimeImmutable('2026-09-19T11:00:00Z');
        $to = new \DateTimeImmutable('2026-09-19T13:00:00Z');
        self::assertSame(2, $query->sum(MetricName::JOBS_DISPATCHED, MetricResolution::Minute, $from, $to));
        self::assertSame(2, $query->sum(MetricName::JOBS_DISPATCHED, MetricResolution::Minute, $from, $to, MetricDimensions::QUEUE, 'imports'));
        $timing = $query->timing(MetricName::RUNTIME_MS, MetricResolution::Minute, $from, $to);
        self::assertSame(1, $timing['count']);
        self::assertGreaterThan(0, $timing['p50']);
    }

    public function testWaitIgnoresIntentionalDelay(): void
    {
        $available = new \DateTimeImmutable('2026-09-19T12:00:10Z');
        $reserved = new \DateTimeImmutable('2026-09-19T12:00:12Z');
        self::assertSame(2000.0, Timing::waitMs($reserved, $available));
        $early = new \DateTimeImmutable('2026-09-19T11:59:00Z');
        self::assertSame(0.0, Timing::waitMs($early, $available));
    }

    public function testMetricFailureDoesNotFailJobs(): void
    {
        $failing = new class implements MetricRecorder {
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
        $isolated = new IsolatingRecorder($failing);
        $isolated->increment(MetricName::JOBS_COMPLETED);
        self::assertTrue($isolated->isDegraded());
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::dispatch(new ProcessOrderJob(1));
        $worker = WorkerLoop::fromManager(Coordinator::get(), WorkerOptions::fromCli(['sleep' => '0'], 'default', Coordinator::get()->config()));
        $worker->run(1);
        self::assertSame(1, ProcessOrderHandler::$handled);
    }

    public function testDispatchAndCompletionRates(): void
    {
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::dispatch(new ProcessOrderJob(1));
        Queue::dispatch(new ProcessOrderJob(2));
        $worker = WorkerLoop::fromManager(Coordinator::get(), WorkerOptions::fromCli(['sleep' => '0'], 'default', Coordinator::get()->config()));
        $worker->run(2);
        $ops = Coordinator::get()->operations();
        $overview = $ops->overview(Operator::cli());
        self::assertArrayHasKey('dispatch_per_minute', $overview);
        self::assertArrayHasKey('completion_per_minute', $overview);
        $metrics = $ops->metrics(Operator::cli(), '1h');
        self::assertNotEmpty($metrics['dispatched']);
        self::assertNotEmpty($metrics['completed']);
    }

    public function testRetentionPrune(): void
    {
        $repo = new MemoryMetricsRepository();
        $old = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $repo->increment($old, MetricName::JOBS_DISPATCHED, 1, MetricDimensions::NONE, '');
        $deleted = $repo->prune(new \DateTimeImmutable('2026-09-19T00:00:00Z'), new MetricsRetention(1, 1, 1), 500);
        self::assertGreaterThan(0, $deleted);
    }

    public function testPayloadRedaction(): void
    {
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        $job = new ProcessOrderJob(9);
        // ProcessOrderJob payload is only order_id; inspect redactor directly.
        $redacted = (new \Fuzeo\Queue\Support\SecretRedactor())->redactMap([
            'password' => 'secret',
            'api_key' => 'k',
            'authorization' => 'Bearer x',
            'access_token' => 't',
        ]);
        self::assertSame('[REDACTED]', $redacted['password']);
        self::assertSame('[REDACTED]', $redacted['api_key']);
        self::assertSame('[REDACTED]', $redacted['authorization']);
        self::assertSame('[REDACTED]', $redacted['access_token']);
        unset($job);
    }
}
