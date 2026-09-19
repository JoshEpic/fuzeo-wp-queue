<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Schedule;

final class ScheduleClaim
{
    public function __construct(
        public readonly string $occurrenceId,
        public readonly string $scheduleId,
        public readonly \DateTimeImmutable $intendedRunAt,
        public readonly string $ownerToken,
        public readonly string $status,
        public readonly ?string $jobId,
        public readonly \DateTimeImmutable $leaseExpiresAt,
    ) {
    }
}
