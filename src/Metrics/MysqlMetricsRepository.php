<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Metrics;

use Fuzeo\Queue\Persistence\Connection;
use Fuzeo\Queue\Persistence\Schema;
use Fuzeo\Queue\Support\Dates;

final class MysqlMetricsRepository implements MetricsRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function increment(
        \DateTimeImmutable $at,
        string $metric,
        int $amount,
        string $dimensionType,
        string $dimensionValue,
    ): void {
        foreach (MetricResolution::cases() as $resolution) {
            $this->upsert(
                $resolution->bucketStart($at),
                $resolution,
                $metric,
                $dimensionType,
                $dimensionValue,
                $amount,
                0.0,
                0.0,
                0.0,
                false
            );
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
            $bucket = $resolution->bucketStart($at);
            $this->upsert($bucket, $resolution, $metric, $dimensionType, $dimensionValue, 1, $value, $value, $value, true);
            $le = $metric . '_le_' . ($histogramBound === PHP_INT_MAX ? 'inf' : (string) $histogramBound);
            $this->upsert($bucket, $resolution, $le, $dimensionType, $dimensionValue, 1, 0.0, 0.0, 0.0, false);
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
        $rows = $this->connection->select(
            'SELECT `bucket`, `resolution`, `metric`, `dimension_type`, `dimension_value`, `count`, `sum`, `min`, `max`
             FROM ' . $this->table() . '
             WHERE `metric` = ? AND `resolution` = ? AND `dimension_type` = ? AND `dimension_value` = ?
               AND `bucket` >= ? AND `bucket` <= ?
             ORDER BY `bucket` ASC',
            [
                $metric,
                $resolution->value,
                $dimensionType,
                $dimensionValue,
                Dates::utc($from)->format('Y-m-d H:i:s'),
                Dates::utc($to)->format('Y-m-d H:i:s'),
            ]
        );
        $out = [];
        foreach ($rows as $row) {
            $bucket = (string) ($row['bucket'] ?? '');
            $out[] = new MetricSample(
                new \DateTimeImmutable($bucket, new \DateTimeZone('UTC')),
                $resolution,
                (string) ($row['metric'] ?? $metric),
                (string) ($row['dimension_type'] ?? $dimensionType),
                (string) ($row['dimension_value'] ?? $dimensionValue),
                (int) ($row['count'] ?? 0),
                (float) ($row['sum'] ?? 0),
                (float) ($row['min'] ?? 0),
                (float) ($row['max'] ?? 0),
            );
        }

        return $out;
    }

    public function prune(\DateTimeImmutable $now, MetricsRetention $retention, int $batchSize = 500): int
    {
        $deleted = 0;
        foreach (MetricResolution::cases() as $resolution) {
            $cutoff = $retention->cutoff($resolution, $now)->format('Y-m-d H:i:s');
            $deleted += $this->connection->execute(
                'DELETE FROM ' . $this->table() . '
                 WHERE `resolution` = ? AND `bucket` < ?
                 LIMIT ' . max(1, min(5000, $batchSize)),
                [$resolution->value, $cutoff]
            );
        }

        return $deleted;
    }

    private function upsert(
        \DateTimeImmutable $bucket,
        MetricResolution $resolution,
        string $metric,
        string $dimensionType,
        string $dimensionValue,
        int $count,
        float $sum,
        float $min,
        float $max,
        bool $timing,
    ): void {
        $sql = 'INSERT INTO ' . $this->table() . ' (
                    `bucket`, `resolution`, `metric`, `dimension_type`, `dimension_value`, `count`, `sum`, `min`, `max`
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    `count` = `count` + VALUES(`count`),
                    `sum` = `sum` + VALUES(`sum`)'
            . ($timing
                ? ', `min` = LEAST(`min`, VALUES(`min`)), `max` = GREATEST(`max`, VALUES(`max`))'
                : '');
        $this->connection->execute($sql, [
            $bucket->format('Y-m-d H:i:s'),
            $resolution->value,
            $metric,
            $dimensionType,
            $dimensionValue,
            $count,
            $sum,
            $min,
            $max,
        ]);
    }

    private function table(): string
    {
        return Schema::quoteTable($this->connection->prefix(), Schema::METRICS);
    }
}
