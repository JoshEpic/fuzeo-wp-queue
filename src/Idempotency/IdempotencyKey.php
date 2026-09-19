<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Idempotency;

use Fuzeo\Queue\Exceptions\QueueException;

final class IdempotencyKey
{
    public static function normalize(string $key): string
    {
        $normalized = trim($key);
        if ($normalized === '') {
            throw new QueueException('Idempotency key must be a non-empty string.');
        }
        if (strlen($normalized) > 191) {
            throw new QueueException('Idempotency key must be 191 characters or fewer.');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $normalized) === 1) {
            throw new QueueException('Idempotency key must not contain control characters.');
        }

        return $normalized;
    }
}
