<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Support;

final class Dates
{
    public static function utc(\DateTimeInterface $value): \DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value->setTimezone(new \DateTimeZone('UTC'));
        }

        return \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'));
    }

    public static function toAtom(\DateTimeInterface $value): string
    {
        return self::utc($value)->format(\DateTimeInterface::ATOM);
    }

    public static function fromAtom(string $value): \DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $value);
        if ($parsed === false) {
            $parsed = new \DateTimeImmutable($value);
        }

        return $parsed->setTimezone(new \DateTimeZone('UTC'));
    }

    public static function fromDatabase(string $value): \DateTimeImmutable
    {
        return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'));
    }

    public static function toDatabase(\DateTimeInterface $value): string
    {
        return self::utc($value)->format('Y-m-d H:i:s.u');
    }
}
