<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Drivers;

/**
 * Optional backend features. Drivers must not pretend to support what they cannot do atomically.
 */
final class DriverCapabilities
{
    public function __construct(
        public readonly bool $priorities = false,
        public readonly bool $blockingReserve = false,
        public readonly bool $atomicUniqueness = false,
        public readonly bool $distributedLocks = false,
        public readonly bool $delayedJobs = true,
        public readonly bool $advancedMetrics = false,
        public readonly bool $durable = false,
        public readonly bool $atomicRateLimits = false,
        public readonly bool $queueConcurrency = false,
        public readonly bool $highConcurrency = false,
    ) {
    }

    public function supports(string $capability): bool
    {
        return match ($capability) {
            'priorities' => $this->priorities,
            'blocking_reserve' => $this->blockingReserve,
            'atomic_uniqueness' => $this->atomicUniqueness,
            'distributed_locks' => $this->distributedLocks,
            'delayed_jobs' => $this->delayedJobs,
            'advanced_metrics' => $this->advancedMetrics,
            'durable' => $this->durable,
            'atomic_rate_limits' => $this->atomicRateLimits,
            'queue_concurrency' => $this->queueConcurrency,
            'high_concurrency' => $this->highConcurrency,
            default => false,
        };
    }
}
