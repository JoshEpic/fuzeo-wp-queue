<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Concurrency;

use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\RateLimit\RateLimit;

/**
 * In-process token buckets and per-queue occupancy. Used by MemoryDriver tests
 * and as the algorithm reference for Redis Lua / MySQL GET_LOCK backends.
 */
final class TokenBucket
{
    /** @var array<string, array{tokens: float, ts: float}> */
    private array $buckets = [];

    /**
     * @return float Seconds to wait when denied (0 when allowed)
     */
    public function consume(RateLimit $limit, float $now): float
    {
        $state = $this->buckets[$limit->key] ?? ['tokens' => (float) $limit->capacity, 'ts' => $now];
        $elapsed = max(0.0, $now - $state['ts']);
        $tokens = min((float) $limit->capacity, $state['tokens'] + $elapsed * $limit->refillPerSecond);
        if ($tokens >= 1.0) {
            $this->buckets[$limit->key] = ['tokens' => $tokens - 1.0, 'ts' => $now];

            return 0.0;
        }
        $this->buckets[$limit->key] = ['tokens' => $tokens, 'ts' => $now];
        $missing = 1.0 - $tokens;

        return $limit->refillPerSecond > 0 ? $missing / $limit->refillPerSecond : 1.0;
    }

    public static function fromEnvelope(Envelope $envelope): ?RateLimit
    {
        $data = $envelope->metadata['_rate'] ?? null;
        if (!is_array($data)) {
            return null;
        }
        /** @var array<string, mixed> $data */
        return RateLimit::fromArray($data);
    }
}
