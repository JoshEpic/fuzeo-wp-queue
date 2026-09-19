<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Redis;

use Fuzeo\Queue\Deployment\RedisDeploymentStore;
use Fuzeo\Queue\Operations\Operator;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Worker\WorkerLoop;
use Fuzeo\Queue\Worker\WorkerOptions;

final class RedisDeploymentTest extends RedisTestCase
{
    public function testRestartGenerationAndLockOwnership(): void
    {
        Coordinator::bootForTesting(
            ['driver' => 'redis', 'redis_namespace' => $this->settings->namespace],
            $this->redisDriver,
            null,
            $this->clock
        );
        $store = new RedisDeploymentStore($this->redisDriver->redis(), $this->redisDriver->keys(), $this->clock);
        $at = $this->clock->now();
        $store->requestRestart('RREDIS', $at);
        $snap = $store->snapshot();
        self::assertSame('RREDIS', $snap->restartGeneration);
        self::assertTrue($store->enterMaintenance('owner-1', $at->modify('+2 minutes'), 'migrate'));
        self::assertFalse($store->enterMaintenance('owner-2', $at->modify('+2 minutes'), 'migrate'));
        self::assertTrue($store->releaseMaintenance('owner-1'));
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::dispatch(new ProcessOrderJob(1));
        Coordinator::get()->operations()->requestRestart(Operator::cli());
        $worker = WorkerLoop::fromManager(
            Coordinator::get(),
            WorkerOptions::fromCli(['sleep' => '0'], 'default', Coordinator::get()->config())
        );
        $worker->run(2);
        self::assertGreaterThanOrEqual(0, $worker->processed());
        Coordinator::reset();
    }
}
