<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Metrics;

final class NullRecorder implements MetricRecorder
{
    public function increment(string $metric, int $amount = 1, ?MetricDimensions $dimensions = null): void
    {
        unset($metric, $amount, $dimensions);
    }

    public function observe(string $metric, float $value, ?MetricDimensions $dimensions = null): void
    {
        unset($metric, $value, $dimensions);
    }

    public function isDegraded(): bool
    {
        return false;
    }
}
