<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\MySql;

use Fuzeo\Queue\Deployment\MysqlDeploymentStore;
use Fuzeo\Queue\Drivers\MySql\MySqlDriver;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Operations\Operator;
use Fuzeo\Queue\Persistence\DatabaseMigrationRepository;
use Fuzeo\Queue\Persistence\Schema;
use Fuzeo\Queue\Persistence\SchemaOwner;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;
use Fuzeo\Queue\Worker\WorkerLoop;
use Fuzeo\Queue\Worker\WorkerOptions;

final class MysqlDeploymentTest extends MysqlTestCase
{
    protected function tearDown(): void
    {
        Coordinator::reset();
        parent::tearDown();
    }

    public function testDurableRestartAndDrainState(): void
    {
        Coordinator::bootForTesting(['driver' => 'mysql'], connection: $this->connection);
        $store = new MysqlDeploymentStore($this->connection);
        $at = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $store->requestRestart('RTEST', $at);
        $store->requestDrain('DTEST', $at);
        $again = new MysqlDeploymentStore($this->connection);
        $snap = $again->snapshot();
        self::assertSame('RTEST', $snap->restartGeneration);
        self::assertSame('DTEST', $snap->drainGeneration);
        $owner = 'owner-a';
        self::assertTrue($again->enterMaintenance($owner, $at->modify('+2 minutes'), 'migrate'));
        self::assertFalse($again->enterMaintenance('owner-b', $at->modify('+2 minutes'), 'migrate'));
        self::assertFalse($again->releaseMaintenance('owner-b'));
        self::assertTrue($again->releaseMaintenance($owner));
    }

    public function testOldWorkerRefusesIncompatibleSchema(): void
    {
        Coordinator::bootForTesting(['driver' => 'mysql'], connection: $this->connection);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::dispatch(new ProcessOrderJob(1));
        $meta = Schema::quoteTable($this->connection->prefix(), Schema::META);
        $this->connection->execute(
            'UPDATE ' . $meta . ' SET meta_value = ? WHERE meta_key = ?',
            ['7', Schema::META_VERSION]
        );
        $driver = Coordinator::get()->driver();
        self::assertInstanceOf(MySqlDriver::class, $driver);
        $this->expectException(\Fuzeo\Queue\Exceptions\DriverException::class);
        $driver->requireCurrentSchema();
    }

    public function testWorkerDoesNotReserveDuringMigrationSchema(): void
    {
        Coordinator::bootForTesting(['driver' => 'mysql'], connection: $this->connection);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::dispatch(new ProcessOrderJob(9));
        $meta = Schema::quoteTable($this->connection->prefix(), Schema::META);
        $this->connection->execute(
            'UPDATE ' . $meta . ' SET meta_value = ? WHERE meta_key = ?',
            ['5', Schema::META_VERSION]
        );
        self::assertSame(5, (new DatabaseMigrationRepository($this->connection))->currentVersion());
        self::assertSame(SchemaOwner::CURRENT_VERSION, SchemaOwner::CURRENT_VERSION);
        $compat = Coordinator::get()->operations()->compatibility();
        self::assertTrue($compat->mustMigrate);
        self::assertFalse($compat->canReserve);
        $worker = WorkerLoop::fromManager(
            Coordinator::get(),
            new WorkerOptions(sleepSeconds: 0, maxJobs: 1, generationCheckInterval: 1)
        );
        $worker->run(3);
        self::assertSame(0, $worker->processed());
        $ready = Coordinator::get()->operations()->readiness(Operator::cli());
        self::assertFalse($ready->ready);
    }

    public function testMigrateCheckAndLockOut(): void
    {
        Coordinator::bootForTesting(['driver' => 'mysql'], connection: $this->connection);
        $check = Coordinator::get()->operations()->migrate(Operator::cli(), true);
        self::assertFalse($check['required']);
        self::assertSame(SchemaOwner::CURRENT_VERSION, $check['current']);
        $other = \Fuzeo\Queue\Persistence\PdoConnection::fromDsn($this->dsn, $this->user, $this->password, 'wp_');
        $other->selectOne('SELECT GET_LOCK(?, ?) AS `locked`', ['fuzeo_queue_schema', 1]);
        try {
            Coordinator::get()->operations()->migrate(Operator::cli());
            self::fail('Expected lock-out.');
        } catch (\Fuzeo\Queue\Exceptions\DriverException $exception) {
            self::assertStringContainsString('lock', strtolower($exception->getMessage()));
        } finally {
            $other->selectOne('SELECT RELEASE_LOCK(?) AS `released`', ['fuzeo_queue_schema']);
        }
    }
}
