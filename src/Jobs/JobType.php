<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Jobs;

use Fuzeo\Queue\Exceptions\InvalidJobTypeException;

/**
 * Stable job type: "vendor.action" with optional extra dotted segments.
 */
final class JobType
{
    private const PATTERN = '/^[a-z][a-z0-9]*(\.[a-z0-9_]+)+$/';

    public static function normalize(string $type): string
    {
        $normalized = strtolower(trim($type));
        self::assertValid($normalized);

        return $normalized;
    }

    public static function assertValid(string $type): void
    {
        if ($type === '' || !preg_match(self::PATTERN, $type)) {
            throw new InvalidJobTypeException(
                'Invalid job type "' . $type . '". Use a dotted lowercase identifier such as acme.process_order.'
            );
        }

        if (strlen($type) > 128) {
            throw new InvalidJobTypeException('Job type must be 128 characters or fewer.');
        }
    }
}
