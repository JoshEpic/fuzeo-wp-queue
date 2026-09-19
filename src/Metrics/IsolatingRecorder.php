<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Metrics;

use Fuzeo\Queue\Runtime\NullLogger;
use Fuzeo\Queue\Runtime\RuntimeLogger;

/**
 * Metrics must never fail a job. Writes are swallowed and flagged degraded.
 */
final class IsolatingRecorder implements MetricRecorder
{
    private bool $degraded = false;

    public function __construct(
        private readonly MetricRecorder $inner,
        private readonly RuntimeLogger $logger = new NullLogger(),
    ) {
    }

    public function increment(string $metric, int $amount = 1, ?MetricDimensions $dimensions = null): void
    {
        try {
            $this->inner->increment($metric, $amount, $dimensions);
        } catch (\Throwable $exception) {
            $this->markDegraded($exception);
        }
    }

    public function observe(string $metric, float $value, ?MetricDimensions $dimensions = null): void
    {
        try {
            $this->inner->observe($metric, $value, $dimensions);
        } catch (\Throwable $exception) {
            $this->markDegraded($exception);
        }
    }

    public function isDegraded(): bool
    {
        return $this->degraded || $this->inner->isDegraded();
    }

    private function markDegraded(\Throwable $exception): void
    {
        $this->degraded = true;
        $this->logger->log('metrics.unavailable', [
            'class' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }
}
