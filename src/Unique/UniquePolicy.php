<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Unique;

use Fuzeo\Queue\Jobs\Envelope;

final class UniquePolicy
{
    public static function ttlSeconds(Envelope $envelope): ?int
    {
        $meta = $envelope->metadata['_unique'] ?? null;
        if (!is_array($meta)) {
            return null;
        }
        $ttl = $meta['ttl'] ?? null;
        if (is_int($ttl) && $ttl > 0) {
            return $ttl;
        }
        if (is_string($ttl) && is_numeric($ttl) && (int) $ttl > 0) {
            return (int) $ttl;
        }

        return null;
    }
}
