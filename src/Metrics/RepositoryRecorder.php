<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Metrics;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Support\SystemClock;

final class RepositoryRecorder implements MetricRecorder
{
    private readonly DurationHistogram $histogram;

    public function __construct(
        private readonly MetricsRepository $repository,
        private readonly Clock $clock = new SystemClock(),
        private readonly bool $includeSite = true,
    ) {
        $this->histogram = new DurationHistogram();
    }

    public function increment(string $metric, int $amount = 1, ?MetricDimensions $dimensions = null): void
    {
        $this->write($metric, $amount, $dimensions, null);
    }

    public function observe(string $metric, float $value, ?MetricDimensions $dimensions = null): void
    {
        $this->write($metric, 1, $dimensions, $value);
    }

    public function isDegraded(): bool
    {
        return false;
    }

    public function repository(): MetricsRepository
    {
        return $this->repository;
    }

    private function write(string $metric, int $amount, ?MetricDimensions $dimensions, ?float $value): void
    {
        $at = $this->clock->now();
        $dims = $dimensions ?? new MetricDimensions();
        $bound = $value === null ? 0 : $this->histogram->boundFor($value);
        foreach ($dims->series($this->includeSite) as [$type, $label]) {
            if ($value === null) {
                $this->repository->increment($at, $metric, $amount, $type, $label);
            } else {
                $this->repository->observe($at, $metric, $value, $type, $label, $bound);
            }
        }
    }
}
