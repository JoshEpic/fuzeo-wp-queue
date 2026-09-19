<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Metrics;

/**
 * Fixed millisecond buckets. Mergeable by summing counts. Percentiles are approximate.
 *
 * Bounds are inclusive upper edges. Values above the last bound land in +Inf.
 */
final class DurationHistogram
{
    /**
     * @var list<int>
     */
    public const BOUNDS_MS = [1, 2, 5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000, 30000, 60000, 120000, 300000];

    /**
     * @param array<int, int> $counts bound => count, including PHP_INT_MAX
     */
    public function __construct(private array $counts = [])
    {
    }

    public function observe(float $milliseconds): self
    {
        $bound = $this->boundFor($milliseconds);
        $copy = $this->counts;
        $copy[$bound] = ($copy[$bound] ?? 0) + 1;

        return new self($copy);
    }

    public function merge(self $other): self
    {
        $copy = $this->counts;
        foreach ($other->counts as $bound => $count) {
            $copy[$bound] = ($copy[$bound] ?? 0) + $count;
        }

        return new self($copy);
    }

    public function boundFor(float $milliseconds): int
    {
        $value = max(0, (int) round($milliseconds));
        foreach (self::BOUNDS_MS as $bound) {
            if ($value <= $bound) {
                return $bound;
            }
        }

        return PHP_INT_MAX;
    }

    /**
     * Approximate percentile using the upper edge of the bucket that crosses p.
     */
    public function percentile(float $p): float
    {
        $total = array_sum($this->counts);
        if ($total === 0) {
            return 0.0;
        }
        $rank = max(1, (int) ceil($p / 100 * $total));
        $cumulative = 0;
        $ordered = self::BOUNDS_MS;
        $ordered[] = PHP_INT_MAX;
        foreach ($ordered as $bound) {
            $cumulative += $this->counts[$bound] ?? 0;
            if ($cumulative >= $rank) {
                return $bound === PHP_INT_MAX ? (float) self::BOUNDS_MS[array_key_last(self::BOUNDS_MS)] : (float) $bound;
            }
        }

        return (float) self::BOUNDS_MS[array_key_last(self::BOUNDS_MS)];
    }

    /**
     * @return array<int, int>
     */
    public function counts(): array
    {
        return $this->counts;
    }
}
