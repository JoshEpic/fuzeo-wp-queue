<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Redis;

use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Metrics\MetricName;
use Fuzeo\Queue\Metrics\MetricResolution;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;
use Fuzeo\Queue\Worker\WorkerLoop;
use Fuzeo\Queue\Worker\WorkerOptions;

final class RedisMetricsTest extends RedisTestCase
{
    public function testAtomicIncrementsAndTtl(): void
    {
        Coordinator::bootForTesting(
            ['driver' => 'redis', 'redis_namespace' => $this->settings->namespace],
            $this->redisDriver,
            null,
            $this->clock
        );
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::dispatch(new ProcessOrderJob(1));
        $worker = WorkerLoop::fromManager(
            Coordinator::get(),
            WorkerOptions::fromCli(['sleep' => '0'], 'default', Coordinator::get()->config())
        );
        $worker->run(1);
        $repo = Coordinator::get()->metricsRepository();
        $from = $this->clock->now()->modify('-1 hour');
        $to = $this->clock->now()->modify('+1 hour');
        $rows = $repo->query(MetricName::JOBS_DISPATCHED, MetricResolution::Minute, $from, $to);
        self::assertNotEmpty($rows);
        Coordinator::reset();
    }
}
