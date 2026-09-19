<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\MySql;

use Fuzeo\Queue\Drivers\MySql\MySqlDriver;
use Fuzeo\Queue\Drivers\ReserveRequest;
use Fuzeo\Queue\Jobs\JobState;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Persistence\AttemptsMigration;
use Fuzeo\Queue\Persistence\BaselineMigration;
use Fuzeo\Queue\Persistence\DatabaseMigrationRepository;
use Fuzeo\Queue\Persistence\MigrationRunner;
use Fuzeo\Queue\Persistence\MysqlAdvisoryLock;
use Fuzeo\Queue\Persistence\Phase5TablesMigration;
use Fuzeo\Queue\Persistence\QueueTablesMigration;
use Fuzeo\Queue\Persistence\Schema;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Retry\FixedBackoff;
use Fuzeo\Queue\Retry\RetryPolicy;
use Fuzeo\Queue\Retention\RetentionPolicy;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Support\FrozenClock;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;
use Fuzeo\Queue\Tests\Support\RetryableFailHandler;
use Fuzeo\Queue\Tests\Support\TerminalFailHandler;
use Fuzeo\Queue\Worker\JobExecutor;
use Fuzeo\Queue\Worker\MappedSiteSwitcher;
use Fuzeo\Queue\Worker\WorkerIdentity;
use Fuzeo\Queue\Worker\WorkerLoop;
use Fuzeo\Queue\Worker\WorkerOptions;

final class MysqlRetryTest extends MysqlTestCase
{
    protected function tearDown(): void
    {
        Coordinator::reset();
        parent::tearDown();
    }

    public function testFailureRecordAndRetryCommittedTogether(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        Coordinator::bootForTesting(['driver' => 'mysql'], null, null, $clock, $this->connection);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), RetryableFailHandler::class);
        $envelope = Queue::on('default')
            ->withRetryPolicy(new RetryPolicy(3, new FixedBackoff(30)))
            ->dispatch(new ProcessOrderJob(1));
        $this->runWorker($clock, 1);
        $driver = Coordinator::get()->driver();
        self::assertInstanceOf(MySqlDriver::class, $driver);
        $job = $driver->get($envelope->jobId);
        self::assertSame(JobState::Pending, $job->state);
        self::assertTrue($job->availableAt > $clock->now());
        $attempts = $driver->attemptsFor($envelope->jobId);
        self::assertCount(1, $attempts);
        self::assertTrue($attempts[0]->willRetry);
        self::assertNull($driver->reserve(new ReserveRequest('default', 'w', 30)));
        $clock->set($clock->now()->add(new \DateInterval('PT30S')));
        self::assertNotNull($driver->reserve(new ReserveRequest('default', 'w', 30)));
    }

    public function testTerminalAndDeadAreNotReserved(): void
    {
        Coordinator::bootForTesting(['driver' => 'mysql'], connection: $this->connection);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), TerminalFailHandler::class);
        $envelope = Queue::dispatch(new ProcessOrderJob(2));
        $this->runWorker(null, 1);
        $driver = Coordinator::get()->driver();
        self::assertInstanceOf(MySqlDriver::class, $driver);
        self::assertSame(JobState::Dead, $driver->get($envelope->jobId)->state);
        self::assertNull($driver->reserve(new ReserveRequest('default', 'w', 30)));
        $revived = $driver->revive($envelope->jobId);
        self::assertSame(JobState::Pending, $revived->state);
        self::assertSame(0, $revived->attempt);
        self::assertCount(1, $driver->attemptsFor($envelope->jobId));
    }

    public function testStaleTokenCannotSettleRetry(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        Coordinator::bootForTesting(['driver' => 'mysql'], null, null, $clock, $this->connection);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::dispatch(new ProcessOrderJob(3));
        $driver = Coordinator::get()->driver();
        self::assertInstanceOf(MySqlDriver::class, $driver);
        $first = $driver->reserve(new ReserveRequest('default', 'a', 10));
        self::assertNotNull($first);
        $clock->set($clock->now()->add(new \DateInterval('PT20S')));
        $second = $driver->reserve(new ReserveRequest('default', 'b', 10));
        self::assertNotNull($second);
        $decision = new \Fuzeo\Queue\Retry\RetryDecision(JobState::Pending, $clock->now(), true, 'retryable', null);
        $record = \Fuzeo\Queue\Retry\AttemptRecord::fromFailure(
            $first->envelope,
            new \RuntimeException('late'),
            $decision,
            'late',
            '',
            $clock->now(),
            'a',
            $first->token->value,
        );
        $this->expectException(\Fuzeo\Queue\Exceptions\DriverException::class);
        $driver->settleOutcome($first, $record, JobState::Pending, $clock->now());
    }

    public function testSchemaV2ToV3(): void
    {
        $repo = new DatabaseMigrationRepository($this->connection);
        $runner = new MigrationRunner($repo, new MysqlAdvisoryLock($this->connection));
        $runner->run([
            new BaselineMigration($this->connection),
            new QueueTablesMigration($this->connection),
        ]);
        self::assertSame(2, $repo->currentVersion());
        $runner->run([
            new BaselineMigration($this->connection),
            new QueueTablesMigration($this->connection),
            new AttemptsMigration($this->connection),
        ]);
        self::assertSame(3, $repo->currentVersion());
        $this->connection->selectOne('SELECT `attempt_id` FROM ' . Schema::quoteTable($this->connection->prefix(), Schema::ATTEMPTS) . ' LIMIT 1');
        $runner->run([
            new BaselineMigration($this->connection),
            new QueueTablesMigration($this->connection),
            new AttemptsMigration($this->connection),
            new Phase5TablesMigration($this->connection),
        ]);
        self::assertSame(4, $repo->currentVersion());
        $this->connection->selectOne('SELECT `unique_id` FROM ' . Schema::quoteTable($this->connection->prefix(), Schema::UNIQUE) . ' LIMIT 1');
    }

    public function testOldSchemaRefusesReserve(): void
    {
        Coordinator::bootForTesting(['driver' => 'mysql'], connection: $this->connection);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::dispatch(new ProcessOrderJob(4));
        $meta = Schema::quoteTable($this->connection->prefix(), Schema::META);
        $this->connection->execute(
            'UPDATE ' . $meta . ' SET `meta_value` = ? WHERE `meta_key` = ?',
            ['2', Schema::META_VERSION]
        );
        $driver = Coordinator::get()->driver();
        self::assertInstanceOf(MySqlDriver::class, $driver);
        $this->expectException(\Fuzeo\Queue\Exceptions\DriverException::class);
        $driver->reserve(new ReserveRequest('default', 'w', 30));
    }

    public function testPruneRemovesCompletedAndAttempts(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        Coordinator::bootForTesting(['driver' => 'mysql'], null, null, $clock, $this->connection);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        $envelope = Queue::dispatch(new ProcessOrderJob(5));
        $this->runWorker($clock, 1);
        $driver = Coordinator::get()->driver();
        self::assertInstanceOf(MySqlDriver::class, $driver);
        self::assertSame(JobState::Completed, $driver->get($envelope->jobId)->state);
        $clock->set($clock->now()->add(new \DateInterval('P10D')));
        $result = $driver->prune(new RetentionPolicy(7, 30), 100);
        self::assertSame(1, $result->completedDeleted);
        $this->expectException(\Fuzeo\Queue\Exceptions\DriverException::class);
        $driver->get($envelope->jobId);
    }

    private function runWorker(?FrozenClock $clock, int $maxJobs): void
    {
        $runtime = Coordinator::get();
        $worker = new WorkerLoop(
            $runtime->driver(),
            new JobExecutor($runtime->jobs()),
            new MappedSiteSwitcher([1 => true]),
            new WorkerOptions(sleepSeconds: 0, maxJobs: $maxJobs),
            WorkerIdentity::generate(),
            clock: $clock ?? $runtime->clock(),
        );
        $worker->run();
    }
}
