<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Jobs;

use Fuzeo\Queue\Exceptions\InvalidQueueNameException;

final class QueueName
{
    public const DEFAULT = 'default';

    private const PATTERN = '/^[a-z][a-z0-9_-]{0,63}$/';

    public static function normalize(string $name): string
    {
        $normalized = strtolower(trim($name));
        self::assertValid($normalized);

        return $normalized;
    }

    public static function assertValid(string $name): void
    {
        if ($name === '' || !preg_match(self::PATTERN, $name)) {
            throw new InvalidQueueNameException(
                'Invalid queue name "' . $name . '". Use a lowercase identifier such as default, imports, or fulfillment.'
            );
        }
    }
}
