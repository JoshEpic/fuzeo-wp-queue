<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Schedule;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Contracts\ExecutionContextResolver;
use Fuzeo\Queue\Exceptions\QueueException;
use Fuzeo\Queue\Jobs\Job;
use Fuzeo\Queue\Jobs\JobRegistry;
use Fuzeo\Queue\Jobs\JobType;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Support\Dates;

/**
 * Plugin registration catalog plus durable operational state.
 */
final class ScheduleBook
{
    public function __construct(
        private readonly ScheduleStore $store,
        private readonly Clock $clock,
        private readonly ExecutionContextResolver $context,
        private readonly JobRegistry $jobs,
        private readonly ScheduleCalculator $calculator = new ScheduleCalculator(),
    ) {
    }

    public function job(string $name, Job $job): PendingSchedule
    {
        if ($name === '' || strlen($name) > 64) {
            throw new QueueException('Schedule name must be 1–64 characters.');
        }

        return new PendingSchedule($this, $name, $job, $this->clock);
    }

    public function persist(PendingSchedule $pending): ScheduleDefinition
    {
        $job = $pending->job();
        $context = $pending->context() ?? $this->context->current();
        $origin = $this->originFor($job);
        $id = OccurrenceId::scheduleId($pending->name(), $origin, $context->networkId, $context->siteId, $context->scope->value);
        $now = $this->clock->now();
        $existing = $this->store->get($id);
        $fingerprint = $this->fingerprint($pending, $job);
        $existingFingerprint = is_array($existing?->metadata) ? ($existing->metadata['_fingerprint'] ?? null) : null;
        if ($existing !== null && $existingFingerprint === $fingerprint) {
            $next = $existing->nextRunAt;
        } else {
            $next = $this->calculator->nextAfter($pending->expression(), $pending->timezoneName(), $now);
        }

        $definition = new ScheduleDefinition(
            scheduleId: $id,
            name: $pending->name(),
            origin: $origin,
            jobType: JobType::normalize($job::type()),
            schemaVersion: $job::schemaVersion(),
            payload: $job->payload(),
            queue: $pending->queue(),
            priority: $pending->priority(),
            context: $context,
            expression: $pending->expression(),
            timezone: $pending->timezoneName(),
            nextRunAt: Dates::utc($next),
            lastRunAt: $existing?->lastRunAt,
            lastOccurrenceId: $existing?->lastOccurrenceId,
            enabled: $pending->enabled(),
            overlap: $pending->overlapPolicy(),
            catchUp: $pending->catchUpPolicy(),
            blockedReason: $existing?->blockedReason,
            metadata: ['_fingerprint' => $fingerprint],
            createdAt: $existing?->createdAt ?? $now,
            updatedAt: $now,
            lastResult: $existing?->lastResult,
        );
        $this->store->save($definition);

        return $definition;
    }

    /**
     * Recompute blocked flags from live code (origin / job registration).
     *
     * @param callable(string): bool $jobRegistered
     */
    public function reconcile(callable $jobRegistered): void
    {
        foreach ($this->store->all() as $schedule) {
            $available = $jobRegistered($schedule->jobType);
            $blocked = $available ? null : 'origin_unavailable';
            if ($schedule->blockedReason === $blocked) {
                continue;
            }
            $this->store->save($schedule->withBlocked($blocked)->withUpdatedAt($this->clock->now()));
        }
    }

    public function store(): ScheduleStore
    {
        return $this->store;
    }

    public function get(string $scheduleId): ?ScheduleDefinition
    {
        return $this->store->get($scheduleId);
    }

    /**
     * @return list<ScheduleDefinition>
     */
    public function all(): array
    {
        return $this->store->all();
    }

    public function enable(string $scheduleId): void
    {
        $schedule = $this->require($scheduleId);
        $this->store->save($schedule->withEnabled(true)->withUpdatedAt($this->clock->now()));
    }

    public function disable(string $scheduleId): void
    {
        $schedule = $this->require($scheduleId);
        $this->store->save($schedule->withEnabled(false)->withUpdatedAt($this->clock->now()));
    }

    private function require(string $scheduleId): ScheduleDefinition
    {
        $schedule = $this->store->get($scheduleId);
        if ($schedule === null) {
            throw new QueueException('Unknown schedule ' . $scheduleId . '.');
        }

        return $schedule;
    }

    private function originFor(Job $job): Origin
    {
        return $this->jobs->get(JobType::normalize($job::type()))->origin;
    }

    private function fingerprint(PendingSchedule $pending, Job $job): string
    {
        return hash('sha256', json_encode([
            $job::type(),
            $job::schemaVersion(),
            $job->payload(),
            $pending->expression()->type->value,
            $pending->expression()->value,
            $pending->timezoneName(),
            $pending->queue(),
            $pending->priority(),
            $pending->overlapPolicy()->value,
            $pending->catchUpPolicy()->value,
        ], JSON_THROW_ON_ERROR));
    }
}
