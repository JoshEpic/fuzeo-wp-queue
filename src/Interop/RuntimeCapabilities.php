<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

final class RuntimeCapabilities
{
    public function __construct(
        public readonly bool $dispatch = true,
        public readonly bool $delay = true,
        public readonly bool $recurring = false,
        public readonly bool $recurringCron = false,
        public readonly bool $chains = false,
        public readonly bool $batches = false,
        public readonly bool $idempotency = false,
        public readonly bool $uniqueness = false,
        public readonly bool $rateLimiting = false,
        public readonly bool $persistentWorkers = false,
        public readonly bool $cancellation = false,
        public readonly bool $visibility = false,
        public readonly bool $longRunningJobs = false,
        public readonly string $executionMode = 'none',
    ) {
    }

    public function supports(string $capability): bool
    {
        return match ($capability) {
            'dispatch' => $this->dispatch,
            'delay', 'later' => $this->delay,
            'recurring' => $this->recurring,
            'recurring_cron', 'cron' => $this->recurringCron,
            'chain', 'chains' => $this->chains,
            'batch', 'batches' => $this->batches,
            'idempotency' => $this->idempotency,
            'uniqueness', 'unique' => $this->uniqueness,
            'rate_limiting', 'rate_limits' => $this->rateLimiting,
            'persistent_workers', 'workers' => $this->persistentWorkers,
            'cancellation' => $this->cancellation,
            'visibility' => $this->visibility,
            'long_running_jobs', 'long_running' => $this->longRunningJobs,
            default => false,
        };
    }

    public function supportsPersistentWorkers(): bool
    {
        return $this->persistentWorkers;
    }

    public function supportsLongRunningJobs(): bool
    {
        return $this->longRunningJobs;
    }

    public function executionMode(): string
    {
        return $this->executionMode;
    }

    /**
     * @return array<string, bool|string>
     */
    public function toArray(): array
    {
        return [
            'dispatch' => $this->dispatch,
            'delay' => $this->delay,
            'recurring' => $this->recurring,
            'recurring_cron' => $this->recurringCron,
            'chains' => $this->chains,
            'batches' => $this->batches,
            'idempotency' => $this->idempotency,
            'uniqueness' => $this->uniqueness,
            'rate_limiting' => $this->rateLimiting,
            'persistent_workers' => $this->persistentWorkers,
            'cancellation' => $this->cancellation,
            'visibility' => $this->visibility,
            'long_running_jobs' => $this->longRunningJobs,
            'execution_mode' => $this->executionMode,
        ];
    }
}
