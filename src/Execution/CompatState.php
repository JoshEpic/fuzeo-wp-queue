<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Execution;

final class CompatState
{
    public function __construct(
        public readonly bool $enabled = false,
        public readonly ?\DateTimeImmutable $lastTickAt = null,
        public readonly ?\DateTimeImmutable $lastSuccessAt = null,
        public readonly int $lastJobsProcessed = 0,
        public readonly string $lastOutcome = '',
        public readonly ?\DateTimeImmutable $lastCronWorkerAt = null,
        public readonly string $lastBlockedReason = '',
        public readonly string $lastBlockedJobType = '',
        public readonly string $lastBlockedQueue = '',
        public readonly bool $wpCronConfigured = false,
        public readonly bool $enabledExplicit = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'last_tick_at' => $this->lastTickAt?->format(\DateTimeInterface::ATOM),
            'last_success_at' => $this->lastSuccessAt?->format(\DateTimeInterface::ATOM),
            'last_jobs_processed' => $this->lastJobsProcessed,
            'last_outcome' => $this->lastOutcome,
            'last_cron_worker_at' => $this->lastCronWorkerAt?->format(\DateTimeInterface::ATOM),
            'last_blocked_reason' => $this->lastBlockedReason,
            'last_blocked_job_type' => $this->lastBlockedJobType,
            'last_blocked_queue' => $this->lastBlockedQueue,
            'wp_cron_configured' => $this->wpCronConfigured,
        ];
    }
}
