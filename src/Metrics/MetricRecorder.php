<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Metrics;

/**
 * Write-only metrics contract. Failures must not affect queue settlement.
 */
interface MetricRecorder
{
    public function increment(string $metric, int $amount = 1, ?MetricDimensions $dimensions = null): void;

    public function observe(string $metric, float $value, ?MetricDimensions $dimensions = null): void;

    public function isDegraded(): bool;
}
