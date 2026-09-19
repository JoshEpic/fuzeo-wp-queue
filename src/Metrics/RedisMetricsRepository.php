<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Metrics;

use Fuzeo\Queue\Redis\RedisClient;
use Fuzeo\Queue\Redis\RedisKeys;

final class RedisMetricsRepository implements MetricsRepository
{
    public function __construct(
        private readonly RedisClient $redis,
        private readonly RedisKeys $keys,
        private readonly MetricsRetention $retention = new MetricsRetention(),
    ) {
    }

    public function increment(
        \DateTimeImmutable $at,
        string $metric,
        int $amount,
        string $dimensionType,
        string $dimensionValue,
    ): void {
        foreach (MetricResolution::cases() as $resolution) {
            $key = $this->keys->metric(
                $resolution->value,
                $resolution->bucketStart($at)->getTimestamp(),
                $metric,
                $dimensionType,
                $dimensionValue
            );
            $this->redis->command('HINCRBY', [$key, 'count', (string) $amount]);
            $this->redis->command('EXPIRE', [$key, (string) $this->retention->ttlSeconds($resolution)]);
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
            $ts = $resolution->bucketStart($at)->getTimestamp();
            $key = $this->keys->metric($resolution->value, $ts, $metric, $dimensionType, $dimensionValue);
            $this->redis->command('HINCRBY', [$key, 'count', '1']);
            $this->redis->command('HINCRBYFLOAT', [$key, 'sum', (string) $value]);
            $this->minMax($key, $value);
            $this->redis->command('EXPIRE', [$key, (string) $this->retention->ttlSeconds($resolution)]);
            $le = $metric . '_le_' . ($histogramBound === PHP_INT_MAX ? 'inf' : (string) $histogramBound);
            $leKey = $this->keys->metric($resolution->value, $ts, $le, $dimensionType, $dimensionValue);
            $this->redis->command('HINCRBY', [$leKey, 'count', '1']);
            $this->redis->command('EXPIRE', [$leKey, (string) $this->retention->ttlSeconds($resolution)]);
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
        $cursor = $resolution->bucketStart($from)->getTimestamp();
        $end = $resolution->bucketStart($to)->getTimestamp();
        $step = $resolution->seconds();
        while ($cursor <= $end) {
            $key = $this->keys->metric($resolution->value, $cursor, $metric, $dimensionType, $dimensionValue);
            $row = $this->redis->command('HGETALL', [$key]);
            if (is_array($row) && $row !== []) {
                $map = $this->pairs($row);
                $out[] = new MetricSample(
                    (new \DateTimeImmutable('@' . $cursor))->setTimezone(new \DateTimeZone('UTC')),
                    $resolution,
                    $metric,
                    $dimensionType,
                    $dimensionValue,
                    (int) ($map['count'] ?? 0),
                    (float) ($map['sum'] ?? 0),
                    (float) ($map['min'] ?? 0),
                    (float) ($map['max'] ?? 0),
                );
            }
            $cursor += $step;
        }

        return $out;
    }

    public function prune(\DateTimeImmutable $now, MetricsRetention $retention, int $batchSize = 500): int
    {
        unset($now, $retention, $batchSize);

        return 0;
    }

    private function minMax(string $key, float $value): void
    {
        $currentMin = $this->redis->command('HGET', [$key, 'min']);
        if (!is_string($currentMin) || $currentMin === '' || $value < (float) $currentMin) {
            $this->redis->command('HSET', [$key, 'min', (string) $value]);
        }
        $currentMax = $this->redis->command('HGET', [$key, 'max']);
        if (!is_string($currentMax) || $currentMax === '' || $value > (float) $currentMax) {
            $this->redis->command('HSET', [$key, 'max', (string) $value]);
        }
    }

    /**
     * @param array<int|string, mixed> $row
     * @return array<string, string>
     */
    private function pairs(array $row): array
    {
        if ($row !== [] && array_is_list($row)) {
            $map = [];
            for ($i = 0; $i + 1 < count($row); $i += 2) {
                $map[(string) $row[$i]] = (string) $row[$i + 1];
            }

            return $map;
        }
        $map = [];
        foreach ($row as $key => $value) {
            $map[(string) $key] = is_scalar($value) ? (string) $value : '';
        }

        return $map;
    }
}
