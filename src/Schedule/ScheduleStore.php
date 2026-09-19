<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Schedule;

interface ScheduleStore
{
    public function save(ScheduleDefinition $schedule): void;

    public function get(string $scheduleId): ?ScheduleDefinition;

    /**
     * @return list<ScheduleDefinition>
     */
    public function all(): array;

    /**
     * @return list<ScheduleDefinition>
     */
    public function due(\DateTimeImmutable $now, int $limit = 50): array;

    public function delete(string $scheduleId): void;

    public function claimOccurrence(
        string $occurrenceId,
        string $scheduleId,
        \DateTimeImmutable $intendedRunAt,
        string $ownerToken,
        \DateTimeImmutable $leaseExpiresAt,
    ): bool;

    public function markDispatched(string $occurrenceId, string $ownerToken, string $jobId): bool;

    public function getClaim(string $occurrenceId): ?ScheduleClaim;

    /**
     * @param array<string, mixed> $row
     */
    public function heartbeat(string $schedulerId, array $row): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function schedulers(): array;
}
