<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Support;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Exceptions\InvalidDelayException;

/**
 * Normalizes delayed-dispatch input to UTC. Past instants stay past and are immediately eligible.
 */
final class Delay
{
    public const MIN_UNIX = 0;

    public const MAX_UNIX = 4102444800;

    public static function resolve(Clock $clock, \DateTimeInterface|int|string $when): \DateTimeImmutable
    {
        if (is_int($when)) {
            if ($when < self::MIN_UNIX || $when > self::MAX_UNIX) {
                throw new InvalidDelayException('Unix timestamp is outside the supported range (1970–2100).');
            }

            return (new \DateTimeImmutable('@' . $when))->setTimezone(new \DateTimeZone('UTC'));
        }

        if ($when instanceof \DateTimeInterface) {
            $utc = Dates::utc($when);
            self::assertRange($utc);

            return $utc;
        }

        $trimmed = trim($when);
        if ($trimmed === '') {
            throw new InvalidDelayException('Delay string must not be empty.');
        }

        $now = $clock->now();
        if (preg_match('/^[+-]/', $trimmed) === 1) {
            try {
                $utc = $now->modify($trimmed);
            } catch (\Exception $exception) {
                throw new InvalidDelayException('Invalid relative delay "' . $trimmed . '".', 0, $exception);
            }
            if ($utc === false) {
                throw new InvalidDelayException('Invalid relative delay "' . $trimmed . '".');
            }
            self::assertRange($utc);

            return $utc;
        }

        try {
            $parsed = new \DateTimeImmutable($trimmed);
        } catch (\Exception $exception) {
            throw new InvalidDelayException('Invalid delay timestamp "' . $trimmed . '".', 0, $exception);
        }
        $utc = Dates::utc($parsed);
        self::assertRange($utc);

        return $utc;
    }

    private static function assertRange(\DateTimeImmutable $utc): void
    {
        $unix = $utc->getTimestamp();
        if ($unix < self::MIN_UNIX || $unix > self::MAX_UNIX) {
            throw new InvalidDelayException('Timestamp is outside the supported range (1970–2100 UTC).');
        }
    }
}
