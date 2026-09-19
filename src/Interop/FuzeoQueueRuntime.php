<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

use Fuzeo\Queue\Core\QueueManager;
use Fuzeo\Queue\Drivers\DriverCapabilities;
use Fuzeo\Queue\Jobs\DispatchOptions;
use Fuzeo\Queue\Jobs\Job;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Schedule\CatchUpPolicy;
use Fuzeo\Queue\Schedule\OverlapPolicy;
use Fuzeo\Queue\Schedule\OccurrenceId;
use Fuzeo\Queue\Schedule\ScheduleDefinition;
use Fuzeo\Queue\Schedule\ScheduleExpression;
use Fuzeo\Queue\Support\Dates;
use Fuzeo\Queue\Support\Delay;

final class FuzeoQueueRuntime implements AsyncRuntime
{
    public function __construct(private readonly QueueManager $manager)
    {
    }

    public function name(): RuntimeName
    {
        return RuntimeName::FuzeoQueue;
    }

    public function healthy(): bool
    {
        try {
            return $this->manager->driver()->health()->ok;
        } catch (\Throwable) {
            return false;
        }
    }

    public function capabilities(): RuntimeCapabilities
    {
        $caps = $this->manager->driver()->capabilities();
        $mode = 'none';
        try {
            $mode = $this->manager->execution()->mode()->value;
        } catch (\Throwable) {
        }

        return self::fromDriver($caps, $mode);
    }

    public static function fromDriver(DriverCapabilities $caps, string $executionMode = 'none'): RuntimeCapabilities
    {
        $persistent = $executionMode === \Fuzeo\Queue\Execution\ExecutionMode::Persistent->value;

        return new RuntimeCapabilities(
            dispatch: true,
            delay: $caps->delayedJobs,
            recurring: $caps->scheduling,
            recurringCron: $caps->scheduling,
            chains: $caps->chains,
            batches: $caps->batches,
            idempotency: $caps->idempotency,
            uniqueness: $caps->atomicUniqueness,
            rateLimiting: $caps->atomicRateLimits,
            persistentWorkers: $caps->durable,
            cancellation: $caps->cancellation,
            visibility: true,
            longRunningJobs: $persistent,
            executionMode: $executionMode,
        );
    }

    public function supports(string $capability): bool
    {
        return $this->capabilities()->supports($capability);
    }

    public function dispatch(Job $job, ?DispatchOptions $options = null): RuntimeDispatch
    {
        $result = $this->manager->dispatcher()->dispatch($job, $options);

        return new RuntimeDispatch(RuntimeName::FuzeoQueue, $result->envelope->jobId, 'job');
    }

    public function later(\DateTimeInterface|int|string $when, Job $job, ?DispatchOptions $options = null): RuntimeDispatch
    {
        $at = Delay::resolve($this->manager->clock(), $when);
        $options = ($options ?? new DispatchOptions())->withAvailableAt($at);

        return $this->dispatch($job, $options);
    }

    public function recurring(string $name, Job $job, RecurringSpec $spec, ?DispatchOptions $options = null): RuntimeDispatch
    {
        $pending = $this->manager->schedules()->job($name, $job)
            ->everySeconds($spec->intervalSeconds)
            ->catchUp(CatchUpPolicy::Skip)
            ->overlap(OverlapPolicy::Skip);
        if ($options?->queue !== null) {
            $pending = $pending->onQueue($options->queue);
        }
        if ($spec->timezone !== 'UTC') {
            $pending = $pending->timezone($spec->timezone);
        }
        $definition = $pending->save();
        if ($spec->firstRunAt !== null) {
            $next = Dates::utc($spec->firstRunAt);
            $updated = new ScheduleDefinition(
                scheduleId: $definition->scheduleId,
                name: $definition->name,
                origin: $definition->origin,
                jobType: $definition->jobType,
                schemaVersion: $definition->schemaVersion,
                payload: $definition->payload,
                queue: $definition->queue,
                priority: $definition->priority,
                context: $definition->context,
                expression: $definition->expression,
                timezone: $definition->timezone,
                nextRunAt: $next,
                lastRunAt: $definition->lastRunAt,
                lastOccurrenceId: $definition->lastOccurrenceId,
                enabled: $definition->enabled,
                overlap: OverlapPolicy::Skip,
                catchUp: CatchUpPolicy::Skip,
                blockedReason: $definition->blockedReason,
                metadata: $definition->metadata,
                createdAt: $definition->createdAt,
                updatedAt: $this->manager->clock()->now(),
                lastResult: $definition->lastResult,
            );
            $this->manager->schedules()->store()->save($updated);
            $definition = $updated;
        }

        return new RuntimeDispatch(RuntimeName::FuzeoQueue, $definition->scheduleId, 'schedule');
    }

    public function pendingOwnedCount(): int
    {
        return 0;
    }

    public static function destinationScheduleId(string $name, Origin $origin, int $networkId, int $siteId, string $scope): string
    {
        return OccurrenceId::scheduleId($name, $origin, $networkId, $siteId, $scope);
    }
}
