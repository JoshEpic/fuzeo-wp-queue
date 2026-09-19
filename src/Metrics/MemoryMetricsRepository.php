<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Metrics;

use Fuzeo\Queue\Support\Dates;

final class MemoryMetricsRepository implements MetricsRepository
{
    /** @var array<string, array{count: int, sum: float, min: float, max: float}> */
    private array $rows = [];

    public function increment(
        \DateTimeImmutable $at,
        string $metric,
        int $amount,
        string $dimensionType,
        string $dimensionValue,
    ): void {
        foreach (MetricResolution::cases() as $resolution) {
            $key = $this->key($resolution->bucketStart($at), $resolution, $metric, $dimensionType, $dimensionValue);
            $row = $this->rows[$key] ?? ['count' => 0, 'sum' => 0.0, 'min' => 0.0, 'max' => 0.0];
            $row['count'] += $amount;
            $this->rows[$key] = $row;
        }
    }

    public function observe(
        \DateTimeImmutable $at,
        string $metric,
        float $value,
        string $dimensionType,
        string $dimensionValue,
        int $histogramBound,
    ): void {
        foreach (MetricResolution::cases() as $resolution) {
            $key = $this->key($resolution->bucketStart($at), $resolution, $metric, $dimensionType, $dimensionValue);
            $row = $this->rows[$key] ?? ['count' => 0, 'sum' => 0.0, 'min' => $value, 'max' => $value];
            $row['count']++;
            $row['sum'] += $value;
            $row['min'] = $row['count'] === 1 ? $value : min($row['min'], $value);
            $row['max'] = max($row['max'], $value);
            $this->rows[$key] = $row;
            $leKey = $this->key(
                $resolution->bucketStart($at),
                $resolution,
                $metric . '_le_' . ($histogramBound === PHP_INT_MAX ? 'inf' : (string) $histogramBound),
                $dimensionType,
                $dimensionValue
            );
            $le = $this->rows[$leKey] ?? ['count' => 0, 'sum' => 0.0, 'min' => 0.0, 'max' => 0.0];
            $le['count']++;
            $this->rows[$leKey] = $le;
        }
    }

    public function query(
        string $metric,
        MetricResolution $resolution,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $dimensionType = MetricDimensions::NONE,
        string $dimensionValue = '',
    ): array {
        $out = [];
        foreach ($this->rows as $key => $row) {
            $parts = explode('|', $key);
            if (count($parts) !== 5) {
                continue;
            }
            [$bucket, $res, $name, $type, $value] = $parts;
            if ($name !== $metric || $res !== $resolution->value || $type !== $dimensionType || $value !== $dimensionValue) {
                continue;
            }
            $at = new \DateTimeImmutable($bucket, new \DateTimeZone('UTC'));
            if ($at < $from || $at > $to) {
                continue;
            }
            $out[] = new MetricSample($at, $resolution, $name, $type, $value, $row['count'], $row['sum'], $row['min'], $row['max']);
        }
        usort($out, static fn (MetricSample $a, MetricSample $b): int => $a->bucket <=> $b->bucket);

        return $out;
    }

    public function prune(\DateTimeImmutable $now, MetricsRetention $retention, int $batchSize = 500): int
    {
        $deleted = 0;
        foreach (array_keys($this->rows) as $key) {
            if ($deleted >= $batchSize) {
                break;
            }
            $parts = explode('|', $key);
            if (count($parts) < 2) {
                continue;
            }
            $resolution = MetricResolution::tryFrom($parts[1]);
            if ($resolution === null) {
                continue;
            }
            $at = new \DateTimeImmutable($parts[0], new \DateTimeZone('UTC'));
            if ($at < $retention->cutoff($resolution, $now)) {
                unset($this->rows[$key]);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * @return array<string, array{count: int, sum: float, min: float, max: float}>
     */
    public function all(): array
    {
        return $this->rows;
    }

    private function key(
        \DateTimeImmutable $bucket,
        MetricResolution $resolution,
        string $metric,
        string $dimensionType,
        string $dimensionValue,
    ): string {
        return Dates::utc($bucket)->format('c') . '|' . $resolution->value . '|' . $metric . '|' . $dimensionType . '|' . $dimensionValue;
    }
}
