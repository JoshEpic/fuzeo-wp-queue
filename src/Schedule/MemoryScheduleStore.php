<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Schedule;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Support\Dates;
use Fuzeo\Queue\Support\SystemClock;

final class MemoryScheduleStore implements ScheduleStore
{
    /** @var array<string, ScheduleDefinition> */
    private array $schedules = [];

    /** @var array<string, ScheduleClaim> */
    private array $claims = [];

    /** @var array<string, array<string, mixed>> */
    private array $heartbeats = [];

    public function __construct(private readonly Clock $clock = new SystemClock())
    {
    }

    public function save(ScheduleDefinition $schedule): void
    {
        $this->schedules[$schedule->scheduleId] = $schedule;
    }

    public function get(string $scheduleId): ?ScheduleDefinition
    {
        return $this->schedules[$scheduleId] ?? null;
    }

    public function all(): array
    {
        return array_values($this->schedules);
    }

    public function due(\DateTimeImmutable $now, int $limit = 50): array
    {
        $due = [];
        foreach ($this->schedules as $schedule) {
            if ($schedule->enabled && $schedule->blockedReason === null && $schedule->nextRunAt <= $now) {
                $due[] = $schedule;
            }
        }
        usort($due, static fn (ScheduleDefinition $a, ScheduleDefinition $b): int => $a->nextRunAt <=> $b->nextRunAt);

        return array_slice($due, 0, $limit);
    }

    public function delete(string $scheduleId): void
    {
        unset($this->schedules[$scheduleId]);
    }

    public function claimOccurrence(
        string $occurrenceId,
        string $scheduleId,
        \DateTimeImmutable $intendedRunAt,
        string $ownerToken,
        \DateTimeImmutable $leaseExpiresAt,
    ): bool {
        $now = $this->clock->now();
        $existing = $this->claims[$occurrenceId] ?? null;
        if ($existing !== null) {
            if ($existing->status === 'dispatched') {
                return false;
            }
            if ($existing->leaseExpiresAt > $now) {
                return false;
            }
        }
        $this->claims[$occurrenceId] = new ScheduleClaim(
            $occurrenceId,
            $scheduleId,
            Dates::utc($intendedRunAt),
            $ownerToken,
            'claimed',
            null,
            Dates::utc($leaseExpiresAt),
        );

        return true;
    }

    public function markDispatched(string $occurrenceId, string $ownerToken, string $jobId): bool
    {
        $claim = $this->claims[$occurrenceId] ?? null;
        if ($claim === null || $claim->ownerToken !== $ownerToken) {
            return false;
        }
        $this->claims[$occurrenceId] = new ScheduleClaim(
            $claim->occurrenceId,
            $claim->scheduleId,
            $claim->intendedRunAt,
            $claim->ownerToken,
            'dispatched',
            $jobId,
            $claim->leaseExpiresAt,
        );

        return true;
    }

    public function getClaim(string $occurrenceId): ?ScheduleClaim
    {
        return $this->claims[$occurrenceId] ?? null;
    }

    public function heartbeat(string $schedulerId, array $row): void
    {
        $row['scheduler_id'] = $schedulerId;
        $row['last_heartbeat_at'] = Dates::toAtom($this->clock->now());
        $this->heartbeats[$schedulerId] = $row;
    }

    public function schedulers(): array
    {
        return array_values($this->heartbeats);
    }
}
