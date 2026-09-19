<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Unit;

use Fuzeo\Queue\Drivers\Memory\MemoryDriver;
use Fuzeo\Queue\Jobs\JobState;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Retry\ExponentialBackoff;
use Fuzeo\Queue\Retry\PercentJitter;
use Fuzeo\Queue\Retry\RetryPolicy;
use Fuzeo\Queue\Retry\SequenceRandom;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Support\FrozenClock;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;
use Fuzeo\Queue\Tests\Support\RetryableFailHandler;
use Fuzeo\Queue\Worker\JobExecutor;
use Fuzeo\Queue\Worker\MappedSiteSwitcher;
use Fuzeo\Queue\Worker\WorkerIdentity;
use Fuzeo\Queue\Worker\WorkerLoop;
use Fuzeo\Queue\Worker\WorkerOptions;
use PHPUnit\Framework\TestCase;

final class RetryLoadTest extends TestCase
{
    protected function tearDown(): void
    {
        Coordinator::reset();
        parent::tearDown();
    }

    public function testJitterSpreadsRetryAvailability(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        Coordinator::bootForTesting([], null, null, $clock);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), RetryableFailHandler::class);
        $policy = new RetryPolicy(3, new ExponentialBackoff(30), 20);
        for ($i = 0; $i < 40; $i++) {
            Queue::on('default')->withRetryPolicy($policy)->dispatch(new ProcessOrderJob($i));
        }
        $random = new SequenceRandom([0.0, 0.25, 0.5, 0.75, 1.0]);
        $runtime = Coordinator::get();
        $worker = new WorkerLoop(
            $runtime->driver(),
            new JobExecutor($runtime->jobs()),
            new MappedSiteSwitcher([1 => true]),
            new WorkerOptions(sleepSeconds: 0, maxJobs: 40),
            WorkerIdentity::generate(),
            clock: $clock,
            random: $random,
        );
        $worker->run(50);
        $driver = $runtime->driver();
        self::assertInstanceOf(MemoryDriver::class, $driver);
        $times = [];
        foreach ($driver->all() as $envelope) {
            self::assertSame(JobState::Pending, $envelope->state);
            $times[$envelope->availableAt->format('U')] = true;
        }
        self::assertGreaterThan(1, count($times));
        self::assertSame(40, $driver->retryingCount());
        self::assertSame(0, $driver->size('default'));
    }

    public function testJitterFormulaIsBounded(): void
    {
        $jitter = new PercentJitter(10, new SequenceRandom([0.0, 1.0]));
        self::assertSame(54, $jitter->apply(60));
        self::assertSame(66, $jitter->apply(60));
    }
}
