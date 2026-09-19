<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\MySql;

use Fuzeo\Queue\Drivers\Failure;
use Fuzeo\Queue\Drivers\MySql\MySqlDriver;
use Fuzeo\Queue\Drivers\ReleaseOptions;
use Fuzeo\Queue\Drivers\ReserveRequest;
use Fuzeo\Queue\Jobs\JobState;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Persistence\DatabaseMigrationRepository;
use Fuzeo\Queue\Persistence\PdoConnection;
use Fuzeo\Queue\Persistence\Schema;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Support\FrozenClock;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;

final class MySqlDriverTest extends MysqlTestCase
{
    protected function tearDown(): void
    {
        Coordinator::reset();
        parent::tearDown();
    }

    public function testEnqueueReserveAckReleaseFailAndDelay(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        Coordinator::bootForTesting(
            ['driver' => 'mysql'],
            null,
            null,
            $clock,
            $this->connection,
        );
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        $envelope = Queue::dispatch(new ProcessOrderJob(11));
        $driver = Coordinator::get()->driver();
        self::assertInstanceOf(MySqlDriver::class, $driver);
        self::assertTrue($driver->health()->ok);
        self::assertSame(1, $driver->size('default'));

        $first = $driver->reserve(new ReserveRequest('default', 'worker-a', 30));
        self::assertNotNull($first);
        self::assertSame(JobState::Reserved, $driver->get($envelope->jobId)->state);

        $driver->release($first, new ReleaseOptions(delaySeconds: 0));
        $again = $driver->reserve(new ReserveRequest('default', 'worker-a', 30));
        self::assertNotNull($again);
        $driver->acknowledge($again);
        self::assertSame(JobState::Completed, $driver->get($envelope->jobId)->state);

        $late = Queue::later($clock->now()->add(new \DateInterval('PT10M')), new ProcessOrderJob(12));
        self::assertNull($driver->reserve(new ReserveRequest('default', 'worker-a', 30)));
        $clock->set($clock->now()->add(new \DateInterval('PT10M')));
        $delayed = $driver->reserve(new ReserveRequest('default', 'worker-a', 30));
        self::assertNotNull($delayed);
        self::assertSame($late->jobId, $delayed->envelope->jobId);
        $driver->fail($delayed, new Failure('nope'));
        self::assertSame(JobState::Failed, $driver->get($late->jobId)->state);
    }

    public function testPriorityAndNamedQueues(): void
    {
        Coordinator::bootForTesting(['driver' => 'mysql'], connection: $this->connection);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::on('low')->withPriority(1)->dispatch(new ProcessOrderJob(1));
        Queue::on('low')->withPriority(9)->dispatch(new ProcessOrderJob(2));
        Queue::on('high')->dispatch(new ProcessOrderJob(3));
        $driver = Coordinator::get()->driver();
        self::assertInstanceOf(MySqlDriver::class, $driver);

        $low = $driver->reserve(new ReserveRequest('low', 'w', 30));
        self::assertNotNull($low);
        self::assertSame(9, $low->envelope->priority);

        self::assertNull($driver->reserve(new ReserveRequest('relay', 'w', 30)));

        $high = $driver->reserve(new ReserveRequest('high', 'w', 30));
        self::assertNotNull($high);
        self::assertSame('high', $high->envelope->queue);
    }

    public function testStaleTokenCannotAckAfterRecovery(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        Coordinator::bootForTesting(['driver' => 'mysql'], null, null, $clock, $this->connection);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::dispatch(new ProcessOrderJob(1));
        $driver = Coordinator::get()->driver();
        self::assertInstanceOf(MySqlDriver::class, $driver);
        $workerA = $driver->reserve(new ReserveRequest('default', 'a', 10));
        self::assertNotNull($workerA);
        $clock->set($clock->now()->add(new \DateInterval('PT20S')));
        $workerB = $driver->reserve(new ReserveRequest('default', 'b', 10));
        self::assertNotNull($workerB);
        $this->expectException(\Fuzeo\Queue\Exceptions\DriverException::class);
        $driver->acknowledge($workerA);
    }

    public function testSecondConnectionCanReserveWhileAnotherHoldsARow(): void
    {
        Coordinator::bootForTesting(['driver' => 'mysql'], connection: $this->connection);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::dispatch(new ProcessOrderJob(1));
        Queue::dispatch(new ProcessOrderJob(2));

        $jobs = Schema::quoteTable($this->connection->prefix(), Schema::JOBS);
        $chosen = $this->connection->selectOne(
            'SELECT `job_id` FROM ' . $jobs . '
             WHERE `queue` = ? AND `state` = ?
             ORDER BY `priority` DESC, `available_at` ASC, `job_id` ASC
             LIMIT 1',
            ['default', JobState::Pending->value]
        );
        self::assertNotNull($chosen);
        self::assertIsString($chosen['job_id'] ?? null);

        $this->connection->begin();
        self::assertSame('READ-COMMITTED', $this->sessionIsolation());

        $locked = $this->connection->selectOne(
            'SELECT * FROM ' . $jobs . ' WHERE `job_id` = ? FOR UPDATE',
            [$chosen['job_id']]
        );
        self::assertNotNull($locked);
        self::assertIsString($locked['job_id'] ?? null);

        $other = PdoConnection::fromDsn($this->dsn, $this->user, $this->password, 'wp_');
        $driverB = new MySqlDriver($other);
        $reserved = $driverB->reserve(new ReserveRequest('default', 'worker-b', 30));
        self::assertNotNull($reserved);
        self::assertNotSame($locked['job_id'], $reserved->envelope->jobId);
        $this->connection->rollBack();
    }

    public function testMigrationIsIdempotent(): void
    {
        Coordinator::bootForTesting(['driver' => 'mysql'], connection: $this->connection);
        Coordinator::bootForTesting(['driver' => 'mysql'], connection: $this->connection);
        $repo = new DatabaseMigrationRepository($this->connection);
        self::assertSame(\Fuzeo\Queue\Persistence\SchemaOwner::CURRENT_VERSION, $repo->currentVersion());
    }
}
