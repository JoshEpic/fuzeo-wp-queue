<?php

declare(strict_types=1);

namespace Fuzeo\Queue;

use Fuzeo\Queue\Core\PendingDispatch;
use Fuzeo\Queue\Core\QueueManager;
use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\Job;
use Fuzeo\Queue\Jobs\JobRegistry;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Testing\FakeQueue;

/**
 * Developer-facing facade. The runtime remains injectable via Coordinator::get().
 */
final class Queue
{
    public static function runtime(): QueueManager
    {
        return Coordinator::get();
    }

    public static function jobs(): JobRegistry
    {
        return self::runtime()->jobs();
    }

    /**
     * @param class-string<Job> $jobClass
     * @param class-string|null $handler
     */
    public static function register(string $jobClass, Origin $origin, ?string $handler = null): void
    {
        self::jobs()->registerJob($jobClass, $origin, $handler);
    }

    public static function dispatch(Job $job): Envelope
    {
        return self::runtime()->dispatcher()->dispatch($job)->envelope;
    }

    public static function dispatchResult(Job $job): \Fuzeo\Queue\Jobs\DispatchResult
    {
        return self::runtime()->dispatcher()->dispatch($job);
    }

    public static function on(string $queue): PendingDispatch
    {
        return self::runtime()->dispatcher()->on($queue);
    }

    /**
     * @param list<\Fuzeo\Queue\Jobs\Job> $jobs
     */
    public static function chain(array $jobs): \Fuzeo\Queue\Orchestration\PendingChain
    {
        return self::runtime()->chain($jobs);
    }

    /**
     * @param list<\Fuzeo\Queue\Jobs\Job> $jobs
     */
    public static function batch(array $jobs): \Fuzeo\Queue\Orchestration\PendingBatch
    {
        return self::runtime()->batch($jobs);
    }

    public static function cancel(string $jobId): \Fuzeo\Queue\Drivers\CancelResult
    {
        return self::runtime()->orchestrator()->cancelJob($jobId);
    }

    public static function later(\DateTimeInterface|int|string $when, Job $job): Envelope
    {
        return self::runtime()->dispatcher()->later($when, $job);
    }

    public static function schedule(): \Fuzeo\Queue\Schedule\ScheduleBook
    {
        return self::runtime()->schedules();
    }

    public static function idempotency(): \Fuzeo\Queue\Idempotency\Idempotency
    {
        return self::runtime()->idempotency();
    }

    public static function fake(): FakeQueue
    {
        return self::runtime()->fake();
    }

    public static function assertDispatched(string $jobTypeOrClass, ?callable $callback = null): void
    {
        self::requireFake()->assertDispatched($jobTypeOrClass, $callback);
    }

    public static function assertNotDispatched(string $jobTypeOrClass): void
    {
        self::requireFake()->assertNotDispatched($jobTypeOrClass);
    }

    public static function assertDispatchedTimes(string $jobTypeOrClass, int $times): void
    {
        self::requireFake()->assertDispatchedTimes($jobTypeOrClass, $times);
    }

    public static function assertNothingDispatched(): void
    {
        self::requireFake()->assertNothingDispatched();
    }

    public static function assertChainDispatched(): void
    {
        self::requireFake()->assertChainDispatched();
    }

    public static function assertBatchDispatched(): void
    {
        self::requireFake()->assertBatchDispatched();
    }

    private static function requireFake(): FakeQueue
    {
        $runtime = self::runtime();
        if (!$runtime->isFaked()) {
            throw new Exceptions\QueueException('Queue is not faked. Call Queue::fake() in your test.');
        }

        return $runtime->fake();
    }
}
