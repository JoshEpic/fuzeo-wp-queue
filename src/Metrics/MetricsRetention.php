<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Metrics;

final class MetricsRetention
{
    public function __construct(
        public readonly int $minuteHours = 48,
        public readonly int $hourDays = 30,
        public readonly int $dayDays = 90,
        public readonly bool $includeSiteDimension = true,
    ) {
    }

    public function ttlSeconds(MetricResolution $resolution): int
    {
        return match ($resolution) {
            MetricResolution::Minute => max(1, $this->minuteHours) * 3600,
            MetricResolution::Hour => max(1, $this->hourDays) * 86400,
            MetricResolution::Day => max(1, $this->dayDays) * 86400,
        };
    }

    public function cutoff(MetricResolution $resolution, \DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify('-' . $this->ttlSeconds($resolution) . ' seconds');
    }
}
