<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\MySql;

use Fuzeo\Queue\Drivers\MySql\MySqlDriver;
use Fuzeo\Queue\Drivers\ReserveRequest;
use Fuzeo\Queue\Execution\ExecutionClass;
use Fuzeo\Queue\Execution\ProcessType;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Persistence\Schema;
use Fuzeo\Queue\Persistence\SchemaOwner;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Tests\Support\ImportCatalogJob;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;
use Fuzeo\Queue\Worker\WorkerIdentity;
use Fuzeo\Queue\Worker\WorkerStatus;

final class Phase12MysqlTest extends MysqlTestCase
{
    protected function tearDown(): void
    {
        Coordinator::reset();
        ProcessOrderHandler::reset();
        parent::tearDown();
    }

    public function testSchemaEightAddsExecutionColumns(): void
    {
        Coordinator::bootForTesting(['driver' => 'mysql'], connection: $this->connection);
        self::assertSame(8, SchemaOwner::CURRENT_VERSION);
        self::assertTrue(Schema::hasColumn($this->connection, Schema::JOBS, 'execution_class'));
        self::assertTrue(Schema::hasColumn($this->connection, Schema::JOBS, 'timeout_seconds'));
        self::assertTrue(Schema::hasColumn($this->connection, Schema::WORKERS, 'process_type'));
    }

    public function testCapabilityAwareReserveSkipsPersistentAndDoesNotChurn(): void
    {
        Coordinator::bootForTesting(['driver' => 'mysql'], connection: $this->connection);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::register(ImportCatalogJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::dispatch(new ImportCatalogJob(1));
        Queue::dispatch(new ProcessOrderJob(2));
        $driver = Coordinator::get()->driver();
        self::assertInstanceOf(MySqlDriver::class, $driver);
        $compat = $driver->reserve(new ReserveRequest('default', 'compat', 30, 0, 'standard', 60));
        self::assertNotNull($compat);
        self::assertSame('acme.process_order', $compat->envelope->jobType);
        $driver->acknowledge($compat);
        $again = $driver->reserve(new ReserveRequest('default', 'compat', 30, 0, 'standard', 60));
        self::assertNull($again);
        $heavy = $driver->reserve(new ReserveRequest('default', 'persistent', 30));
        self::assertNotNull($heavy);
        self::assertSame(ExecutionClass::Persistent, $heavy->envelope->executionClass());
    }

    public function testCompatTickAndPersistentWorkerBackoff(): void
    {
        Coordinator::bootForTesting(['driver' => 'mysql', 'compatibility_enabled' => true], connection: $this->connection);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::dispatch(new ProcessOrderJob(1));
        $first = Queue::execution()->tick(true);
        self::assertSame('ok', $first->outcome);
        self::assertSame(1, $first->jobsProcessed);

        Queue::dispatch(new ProcessOrderJob(2));
        $driver = Coordinator::get()->driver();
        self::assertInstanceOf(MySqlDriver::class, $driver);
        $identity = WorkerIdentity::generate(processType: ProcessType::Persistent);
        $driver->workerStore()->register($identity, ['default']);
        $driver->workerStore()->heartbeat($identity->workerId, 0, WorkerStatus::Idle);
        $skipped = Queue::execution()->tick(false);
        self::assertSame('skipped', $skipped->outcome);
        self::assertSame('persistent_workers_healthy', $skipped->reason);
        $left = $driver->reserve(new ReserveRequest('default', 'w', 30));
        self::assertNotNull($left);
    }

    public function testBacklogDrainsAcrossTicks(): void
    {
        Coordinator::bootForTesting(
            ['driver' => 'mysql', 'compatibility_max_jobs' => 5],
            connection: $this->connection
        );
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        for ($i = 0; $i < 12; $i++) {
            Queue::dispatch(new ProcessOrderJob($i));
        }
        $total = 0;
        for ($t = 0; $t < 5; $t++) {
            $total += Queue::execution()->tick(true)->jobsProcessed;
        }
        self::assertSame(12, $total);
        self::assertSame(12, ProcessOrderHandler::$handled);
    }
}
