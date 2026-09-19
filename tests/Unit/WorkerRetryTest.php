<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Unit;

use Fuzeo\Queue\Drivers\Memory\MemoryDriver;
use Fuzeo\Queue\Jobs\JobState;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Retry\FixedBackoff;
use Fuzeo\Queue\Retry\RetryPolicy;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Tests\Support\FlakyHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;
use Fuzeo\Queue\Tests\Support\RetryableFailHandler;
use Fuzeo\Queue\Tests\Support\TerminalFailHandler;
use Fuzeo\Queue\Worker\JobExecutor;
use Fuzeo\Queue\Worker\MappedSiteSwitcher;
use Fuzeo\Queue\Worker\WorkerIdentity;
use Fuzeo\Queue\Worker\WorkerLoop;
use Fuzeo\Queue\Worker\WorkerOptions;
use PHPUnit\Framework\TestCase;

final class WorkerRetryTest extends TestCase
{
    protected function tearDown(): void
    {
        Coordinator::reset();
        parent::tearDown();
    }

    public function testRetryableFailureThenSuccess(): void
    {
        Coordinator::bootForTesting();
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), FlakyHandler::class);
        Queue::on('default')
            ->withRetryPolicy(new RetryPolicy(3, new FixedBackoff(0)))
            ->dispatch(new ProcessOrderJob(1));
        $runtime = Coordinator::get();
        $worker = new WorkerLoop(
            $runtime->driver(),
            new JobExecutor($runtime->jobs()),
            new MappedSiteSwitcher([1 => true]),
            new WorkerOptions(sleepSeconds: 0, maxJobs: 2),
            WorkerIdentity::generate(),
        );
        $worker->run(15);
        $driver = $runtime->driver();
        self::assertInstanceOf(MemoryDriver::class, $driver);
        self::assertSame(JobState::Completed, $driver->all()[0]->state);
        self::assertCount(1, $driver->attemptsFor($driver->all()[0]->jobId));
    }

    public function testExhaustionDeadLetters(): void
    {
        Coordinator::bootForTesting();
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), RetryableFailHandler::class);
        Queue::on('default')
            ->withMaxAttempts(2)
            ->withRetryPolicy(new RetryPolicy(2, new FixedBackoff(0)))
            ->dispatch(new ProcessOrderJob(1));
        $runtime = Coordinator::get();
        $worker = new WorkerLoop(
            $runtime->driver(),
            new JobExecutor($runtime->jobs()),
            new MappedSiteSwitcher([1 => true]),
            new WorkerOptions(sleepSeconds: 0, maxJobs: 5),
            WorkerIdentity::generate(),
        );
        $worker->run(15);
        $driver = $runtime->driver();
        self::assertInstanceOf(MemoryDriver::class, $driver);
        $job = $driver->all()[0];
        self::assertSame(JobState::Dead, $job->state);
        self::assertSame(2, $job->attempt);
        self::assertCount(2, $driver->attemptsFor($job->jobId));
    }

    public function testTerminalFailureDoesNotRetry(): void
    {
        Coordinator::bootForTesting();
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), TerminalFailHandler::class);
        Queue::dispatch(new ProcessOrderJob(1));
        $runtime = Coordinator::get();
        $worker = new WorkerLoop(
            $runtime->driver(),
            new JobExecutor($runtime->jobs()),
            new MappedSiteSwitcher([1 => true]),
            new WorkerOptions(sleepSeconds: 0, maxJobs: 3),
            WorkerIdentity::generate(),
        );
        $worker->run(15);
        $driver = $runtime->driver();
        self::assertInstanceOf(MemoryDriver::class, $driver);
        self::assertSame(JobState::Dead, $driver->all()[0]->state);
        self::assertCount(1, $driver->attemptsFor($driver->all()[0]->jobId));
    }

    public function testUnknownTypeThenRestoreAndManualRetry(): void
    {
        Coordinator::bootForTesting();
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::dispatch(new ProcessOrderJob(7));
        Coordinator::get()->jobs()->forget(ProcessOrderJob::type());
        ProcessOrderHandler::reset();
        $runtime = Coordinator::get();
        $worker = new WorkerLoop(
            $runtime->driver(),
            new JobExecutor($runtime->jobs()),
            new MappedSiteSwitcher([1 => true]),
            new WorkerOptions(sleepSeconds: 0, maxJobs: 1),
            WorkerIdentity::generate(),
        );
        $worker->run(15);
        $driver = $runtime->driver();
        self::assertInstanceOf(MemoryDriver::class, $driver);
        $job = $driver->all()[0];
        self::assertSame(JobState::Dead, $job->state);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        $driver->revive($job->jobId);
        $worker = new WorkerLoop(
            $runtime->driver(),
            new JobExecutor($runtime->jobs()),
            new MappedSiteSwitcher([1 => true]),
            new WorkerOptions(sleepSeconds: 0, maxJobs: 1),
            WorkerIdentity::generate(),
        );
        $worker->run(15);
        self::assertSame(JobState::Completed, $driver->get($job->jobId)->state);
        self::assertSame(1, ProcessOrderHandler::$handled);
    }

    public function testDeletedSiteStaysTerminalOnRevive(): void
    {
        Coordinator::bootForTesting();
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::on('default')->onSite(1, 42)->dispatch(new ProcessOrderJob(9));
        $runtime = Coordinator::get();
        $worker = new WorkerLoop(
            $runtime->driver(),
            new JobExecutor($runtime->jobs()),
            new MappedSiteSwitcher([1 => true]),
            new WorkerOptions(sleepSeconds: 0, maxJobs: 1),
            WorkerIdentity::generate(),
        );
        $worker->run(15);
        $driver = $runtime->driver();
        self::assertInstanceOf(MemoryDriver::class, $driver);
        $job = $driver->all()[0];
        self::assertSame(JobState::Dead, $job->state);
        self::assertSame(42, $job->context->siteId);
        $driver->revive($job->jobId);
        $worker = new WorkerLoop(
            $runtime->driver(),
            new JobExecutor($runtime->jobs()),
            new MappedSiteSwitcher([1 => true]),
            new WorkerOptions(sleepSeconds: 0, maxJobs: 1),
            WorkerIdentity::generate(),
        );
        $worker->run(15);
        self::assertSame(JobState::Dead, $driver->get($job->jobId)->state);
        self::assertSame(42, $driver->get($job->jobId)->context->siteId);
    }

    public function testPoisonLeaseExhaustion(): void
    {
        $clock = new \Fuzeo\Queue\Support\FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        Coordinator::bootForTesting([], null, null, $clock);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::on('default')->withMaxAttempts(2)->dispatch(new ProcessOrderJob(1));
        $driver = Coordinator::get()->driver();
        self::assertInstanceOf(MemoryDriver::class, $driver);
        $first = $driver->reserve(new \Fuzeo\Queue\Drivers\ReserveRequest('default', 'a', 10));
        self::assertNotNull($first);
        self::assertSame(1, $first->envelope->attempt);
        $clock->set($clock->now()->add(new \DateInterval('PT20S')));
        $second = $driver->reserve(new \Fuzeo\Queue\Drivers\ReserveRequest('default', 'b', 10));
        self::assertNotNull($second);
        self::assertSame(2, $second->envelope->attempt);
        $clock->set($clock->now()->add(new \DateInterval('PT20S')));
        self::assertNull($driver->reserve(new \Fuzeo\Queue\Drivers\ReserveRequest('default', 'c', 10)));
        self::assertSame(JobState::Dead, $driver->get($second->envelope->jobId)->state);
    }
}
