<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Support;

use Fuzeo\Queue\Contracts\Clock;

final class FrozenClock implements Clock
{
    public function __construct(private \DateTimeImmutable $now)
    {
        $this->now = $now->setTimezone(new \DateTimeZone('UTC'));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function set(\DateTimeImmutable $now): void
    {
        $this->now = $now->setTimezone(new \DateTimeZone('UTC'));
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->add(new \DateInterval('PT' . $seconds . 'S'));
    }
}
