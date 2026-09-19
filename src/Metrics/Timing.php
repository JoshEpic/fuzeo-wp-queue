<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Metrics;

/**
 * Queue wait is reservation time minus available_at (eligibility), not created_at.
 * Intentional delay is not backlog.
 */
final class Timing
{
    public static function waitMs(\DateTimeImmutable $reservedAt, \DateTimeImmutable $availableAt): float
    {
        $ms = ($reservedAt->getTimestamp() - $availableAt->getTimestamp()) * 1000.0;

        return max(0.0, $ms);
    }

    public static function runtimeMs(\DateTimeImmutable $startedAt, \DateTimeImmutable $finishedAt): float
    {
        $start = $startedAt->getTimestamp() * 1000 + (int) $startedAt->format('v');
        $end = $finishedAt->getTimestamp() * 1000 + (int) $finishedAt->format('v');

        return max(0.0, (float) ($end - $start));
    }
}
