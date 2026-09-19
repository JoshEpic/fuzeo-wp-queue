<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Unit;

use Fuzeo\Queue\Drivers\Memory\MemoryDriver;
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
use PHPUnit\Framework\TestCase;

final class WorkerLoopTest extends TestCase
{
    protected function setUp(): void
    {
        ProcessOrderHandler::reset();
        Coordinator::bootForTesting();
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
    }

    protected function tearDown(): void
    {
        Coordinator::reset();
    }

    public function testProcessesDispatchedJobAndStopsAtMaxJobs(): void
    {
        Queue::dispatch(new ProcessOrderJob(1));
        $runtime = Coordinator::get();
        $worker = new WorkerLoop(
            $runtime->driver(),
            new JobExecutor($runtime->jobs()),
            new MappedSiteSwitcher([1 => true]),
            new WorkerOptions(sleepSeconds: 0, maxJobs: 1),
            WorkerIdentity::generate(),
        );
        $worker->run();
        self::assertSame(1, $worker->processed());
        self::assertSame(1, ProcessOrderHandler::$handled);
        $driver = $runtime->driver();
        self::assertInstanceOf(MemoryDriver::class, $driver);
        self::assertSame(JobState::Completed, $driver->all()[0]->state);
    }

    public function testDeletedSiteFailsWithoutExecutingHandler(): void
    {
        Queue::on('default')->onSite(1, 42)->dispatch(new ProcessOrderJob(9));
        $runtime = Coordinator::get();
        $worker = new WorkerLoop(
            $runtime->driver(),
            new JobExecutor($runtime->jobs()),
            new MappedSiteSwitcher([1 => true]),
            new WorkerOptions(sleepSeconds: 0, maxJobs: 1),
            WorkerIdentity::generate(),
        );
        $worker->run();
        self::assertSame(0, ProcessOrderHandler::$handled);
        $driver = $runtime->driver();
        self::assertInstanceOf(MemoryDriver::class, $driver);
        self::assertSame(JobState::Failed, $driver->all()[0]->state);
    }

    public function testUnknownHandlerFailsDeterministically(): void
    {
        Coordinator::reset();
        Coordinator::bootForTesting();
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderJob::class);
        Queue::dispatch(new ProcessOrderJob(3));
        $runtime = Coordinator::get();
        $worker = new WorkerLoop(
            $runtime->driver(),
            new JobExecutor($runtime->jobs()),
            new MappedSiteSwitcher([1 => true]),
            new WorkerOptions(sleepSeconds: 0, maxJobs: 1),
            WorkerIdentity::generate(),
        );
        $worker->run();
        $driver = $runtime->driver();
        self::assertInstanceOf(MemoryDriver::class, $driver);
        self::assertSame(JobState::Failed, $driver->all()[0]->state);
    }
}
