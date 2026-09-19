<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\MySql;

use Fuzeo\Queue\Drivers\MySql\MySqlDriver;
use Fuzeo\Queue\Jobs\JobState;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;
use Fuzeo\Queue\Worker\JobExecutor;
use Fuzeo\Queue\Worker\MappedSiteSwitcher;
use Fuzeo\Queue\Worker\WorkerIdentity;
use Fuzeo\Queue\Worker\WorkerLoop;
use Fuzeo\Queue\Worker\WorkerOptions;

final class MysqlWorkerTest extends MysqlTestCase
{
    protected function tearDown(): void
    {
        Coordinator::reset();
        parent::tearDown();
    }

    public function testWorkerCompletesDurableJob(): void
    {
        ProcessOrderHandler::reset();
        Coordinator::bootForTesting(['driver' => 'mysql'], connection: $this->connection);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        $envelope = Queue::dispatch(new ProcessOrderJob(99));
        $runtime = Coordinator::get();
        $worker = new WorkerLoop(
            $runtime->driver(),
            new JobExecutor($runtime->jobs()),
            new MappedSiteSwitcher([1 => true]),
            new WorkerOptions(sleepSeconds: 0, maxJobs: 1),
            WorkerIdentity::generate(),
        );
        $worker->run();
        self::assertSame(1, ProcessOrderHandler::$handled);
        $driver = $runtime->driver();
        self::assertInstanceOf(MySqlDriver::class, $driver);
        self::assertSame(JobState::Completed, $driver->get($envelope->jobId)->state);
    }
}
