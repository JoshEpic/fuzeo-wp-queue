<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Metrics;

final class MetricsQuery
{
    public function __construct(private readonly MetricsRepository $repository)
    {
    }

    /**
     * @return list<MetricSample>
     */
    public function series(
        string $metric,
        MetricResolution $resolution,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $dimensionType = MetricDimensions::NONE,
        string $dimensionValue = '',
    ): array {
        return $this->repository->query($metric, $resolution, $from, $to, $dimensionType, $dimensionValue);
    }

    public function sum(
        string $metric,
        MetricResolution $resolution,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $dimensionType = MetricDimensions::NONE,
        string $dimensionValue = '',
    ): int {
        $total = 0;
        foreach ($this->series($metric, $resolution, $from, $to, $dimensionType, $dimensionValue) as $sample) {
            $total += $sample->count;
        }

        return $total;
    }

    /**
     * @return array{p50: float, p95: float, p99: float, avg: float, count: int}
     */
    public function timing(
        string $metric,
        MetricResolution $resolution,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $dimensionType = MetricDimensions::NONE,
        string $dimensionValue = '',
    ): array {
        $samples = $this->series($metric, $resolution, $from, $to, $dimensionType, $dimensionValue);
        $count = 0;
        $sum = 0.0;
        $histogram = new DurationHistogram();
        foreach ($samples as $sample) {
            $count += $sample->count;
            $sum += $sample->sum;
        }
        foreach (DurationHistogram::BOUNDS_MS as $bound) {
            $le = $this->sum($metric . '_le_' . $bound, $resolution, $from, $to, $dimensionType, $dimensionValue);
            for ($i = 0; $i < $le; $i++) {
                $histogram = $histogram->observe((float) $bound);
            }
        }
        $inf = $this->sum($metric . '_le_inf', $resolution, $from, $to, $dimensionType, $dimensionValue);
        for ($i = 0; $i < $inf; $i++) {
            $histogram = $histogram->observe(300000.0);
        }

        return [
            'p50' => $histogram->percentile(50),
            'p95' => $histogram->percentile(95),
            'p99' => $histogram->percentile(99),
            'avg' => $count === 0 ? 0.0 : $sum / $count,
            'count' => $count,
        ];
    }

    public function ratePerMinute(string $metric, \DateTimeImmutable $from, \DateTimeImmutable $to, string $dimensionType = MetricDimensions::NONE, string $dimensionValue = ''): float
    {
        $minutes = max(1, (int) ceil(($to->getTimestamp() - $from->getTimestamp()) / 60));
        $resolution = ($to->getTimestamp() - $from->getTimestamp()) <= 7200
            ? MetricResolution::Minute
            : (($to->getTimestamp() - $from->getTimestamp()) <= 86400 * 2 ? MetricResolution::Hour : MetricResolution::Day);

        return $this->sum($metric, $resolution, $from, $to, $dimensionType, $dimensionValue) / $minutes;
    }
}
