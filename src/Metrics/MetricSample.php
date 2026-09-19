<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Metrics;

final class MetricSample
{
    public function __construct(
        public readonly \DateTimeImmutable $bucket,
        public readonly MetricResolution $resolution,
        public readonly string $metric,
        public readonly string $dimensionType,
        public readonly string $dimensionValue,
        public readonly int $count,
        public readonly float $sum,
        public readonly float $min,
        public readonly float $max,
    ) {
    }

    public function average(): float
    {
        return $this->count === 0 ? 0.0 : $this->sum / $this->count;
    }
}
