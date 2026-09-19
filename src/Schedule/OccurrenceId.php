<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Schedule;

use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Support\Dates;

final class OccurrenceId
{
    public static function make(string $scheduleId, \DateTimeImmutable $intendedRunAt): string
    {
        return hash('sha256', $scheduleId . '|' . Dates::toAtom($intendedRunAt));
    }

    public static function scheduleId(string $name, Origin $origin, int $networkId, int $siteId, string $scope): string
    {
        return hash('sha256', implode("\n", [
            $origin->package,
            $name,
            (string) $networkId,
            (string) $siteId,
            $scope,
        ]));
    }
}
