<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Execution;

final class TickResult
{
    public function __construct(
        public readonly string $outcome,
        public readonly int $jobsProcessed = 0,
        public readonly int $schedulesDispatched = 0,
        public readonly int $reconciled = 0,
        public readonly string $reason = '',
        public readonly int $blocked = 0,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'jobs_processed' => $this->jobsProcessed,
            'schedules_dispatched' => $this->schedulesDispatched,
            'reconciled' => $this->reconciled,
            'reason' => $this->reason,
            'blocked' => $this->blocked,
        ];
    }
}
