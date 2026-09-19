<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Metrics;

enum MetricResolution: string
{
    case Minute = 'minute';
    case Hour = 'hour';
    case Day = 'day';

    public function seconds(): int
    {
        return match ($this) {
            self::Minute => 60,
            self::Hour => 3600,
            self::Day => 86400,
        };
    }

    public function bucketStart(\DateTimeImmutable $at): \DateTimeImmutable
    {
        $ts = $at->getTimestamp();
        $aligned = intdiv($ts, $this->seconds()) * $this->seconds();

        return (new \DateTimeImmutable('@' . $aligned))->setTimezone(new \DateTimeZone('UTC'));
    }
}
