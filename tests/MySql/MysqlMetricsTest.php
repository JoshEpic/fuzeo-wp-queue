<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\MySql;

use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Metrics\MetricName;
use Fuzeo\Queue\Metrics\MetricResolution;
use Fuzeo\Queue\Persistence\DatabaseMigrationRepository;
use Fuzeo\Queue\Persistence\SchemaOwner;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;
use Fuzeo\Queue\Worker\WorkerLoop;
use Fuzeo\Queue\Worker\WorkerOptions;

final class MysqlMetricsTest extends MysqlTestCase
{
    protected function tearDown(): void
    {
        Coordinator::reset();
        parent::tearDown();
    }

    public function testIncrementsAndPrune(): void
    {
        Coordinator::bootForTesting(['driver' => 'mysql'], null, null, null, $this->connection);
        self::assertSame(SchemaOwner::CURRENT_VERSION, (new DatabaseMigrationRepository($this->connection))->currentVersion());
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::dispatch(new ProcessOrderJob(1));
        Queue::dispatch(new ProcessOrderJob(2));
        $worker = WorkerLoop::fromManager(
            Coordinator::get(),
            WorkerOptions::fromCli(['sleep' => '0'], 'default', Coordinator::get()->config())
        );
        $worker->run(2);
        $repo = Coordinator::get()->metricsRepository();
        $from = (new \DateTimeImmutable('-1 hour'));
        $to = new \DateTimeImmutable('+1 hour');
        $rows = $repo->query(MetricName::JOBS_COMPLETED, MetricResolution::Minute, $from, $to);
        $count = 0;
        foreach ($rows as $row) {
            $count += $row->count;
        }
        self::assertGreaterThanOrEqual(2, $count);
        $deleted = $repo->prune((new \DateTimeImmutable('+200 days')), Coordinator::get()->config()->metricsEnabled
            ? new \Fuzeo\Queue\Metrics\MetricsRetention(0, 0, 0)
            : new \Fuzeo\Queue\Metrics\MetricsRetention(), 500);
        self::assertGreaterThanOrEqual(0, $deleted);
    }

    public function testConcurrentIncrements(): void
    {
        Coordinator::bootForTesting(['driver' => 'mysql'], null, null, null, $this->connection);
        $repo = Coordinator::get()->metricsRepository();
        $now = new \DateTimeImmutable('2026-09-19T12:00:00Z');
        $repo->increment($now, MetricName::JOBS_DISPATCHED, 1, 'none', '');
        $repo->increment($now, MetricName::JOBS_DISPATCHED, 1, 'none', '');
        $rows = $repo->query(MetricName::JOBS_DISPATCHED, MetricResolution::Minute, $now->modify('-1 minute'), $now->modify('+1 minute'));
        $count = 0;
        foreach ($rows as $row) {
            $count += $row->count;
        }
        self::assertSame(2, $count);
    }
}
