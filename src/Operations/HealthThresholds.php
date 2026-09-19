<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Operations;

/**
 * Configurable thresholds. One spike does not flip critical by itself.
 */
final class HealthThresholds
{
    public function __construct(
        public readonly int $lagDegradedSeconds = 60,
        public readonly int $lagCriticalSeconds = 300,
        public readonly int $deadDegraded = 10,
        public readonly int $deadCritical = 100,
        public readonly int $backlogCritical = 1000,
        public readonly int $staleWorkerSeconds = 30,
        public readonly int $schedulerStaleSeconds = 90,
    ) {
    }
}
