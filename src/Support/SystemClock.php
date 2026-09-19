<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Support;

use Fuzeo\Queue\Contracts\Clock;

final class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
