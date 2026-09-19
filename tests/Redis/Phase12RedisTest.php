<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Redis;

use Fuzeo\Queue\Drivers\Redis\RedisDriver;
use Fuzeo\Queue\Drivers\ReserveRequest;
use Fuzeo\Queue\Execution\ExecutionClass;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Redis\RedisScripts;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Tests\Support\ImportCatalogJob;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;

final class Phase12RedisTest extends RedisTestCase
{
    protected function tearDown(): void
    {
        Coordinator::reset();
        ProcessOrderHandler::reset();
        parent::tearDown();
    }

    public function testLuaVersionFive(): void
    {
        self::assertSame('5', RedisScripts::VERSION);
    }

    public function testCompatLaneSkipsPersistentJobs(): void
    {
        Coordinator::bootForTesting(['driver' => 'redis'], $this->redisDriver, clock: $this->clock);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::register(ImportCatalogJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::dispatch(new ImportCatalogJob(1));
        Queue::dispatch(new ProcessOrderJob(2));
        $driver = Coordinator::get()->driver();
        self::assertInstanceOf(RedisDriver::class, $driver);
        $compat = $driver->reserve(new ReserveRequest('default', 'compat', 30, 0, 'standard', 60));
        self::assertNotNull($compat);
        self::assertSame('acme.process_order', $compat->envelope->jobType);
        $driver->acknowledge($compat);
        self::assertNull($driver->reserve(new ReserveRequest('default', 'compat', 30, 0, 'standard', 60)));
        $heavy = $driver->reserve(new ReserveRequest('default', 'full', 30));
        self::assertNotNull($heavy);
        self::assertSame(ExecutionClass::Persistent, $heavy->envelope->executionClass());
    }

    public function testRepeatedOneShotWorkersDrainQueue(): void
    {
        Coordinator::bootForTesting(['driver' => 'redis', 'compatibility_max_jobs' => 2], $this->redisDriver, clock: $this->clock);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        for ($i = 0; $i < 5; $i++) {
            Queue::dispatch(new ProcessOrderJob($i));
        }
        $total = 0;
        for ($t = 0; $t < 4; $t++) {
            $total += Queue::execution()->tick(true)->jobsProcessed;
        }
        self::assertSame(5, $total);
    }
}
