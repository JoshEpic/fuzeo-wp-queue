<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Metrics;

interface MetricsRepository
{
    public function increment(
        \DateTimeImmutable $at,
        string $metric,
        int $amount,
        string $dimensionType,
        string $dimensionValue,
    ): void;

    public function observe(
        \DateTimeImmutable $at,
        string $metric,
        float $value,
        string $dimensionType,
        string $dimensionValue,
        int $histogramBound,
    ): void;

    /**
     * @return list<MetricSample>
     */
    public function query(
        string $metric,
        MetricResolution $resolution,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $dimensionType = MetricDimensions::NONE,
        string $dimensionValue = '',
    ): array;

    public function prune(\DateTimeImmutable $now, MetricsRetention $retention, int $batchSize = 500): int;
}
