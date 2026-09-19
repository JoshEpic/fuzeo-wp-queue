<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Retry;

final class FixedBackoff implements Backoff
{
    public function __construct(private readonly int $seconds = 30)
    {
        if ($this->seconds < 0) {
            throw new \InvalidArgumentException('Fixed backoff cannot be negative.');
        }
    }

    public function delaySeconds(int $failedAttempt): int
    {
        unset($failedAttempt);

        return $this->seconds;
    }

    public function toArray(): array
    {
        return ['type' => 'fixed', 'seconds' => $this->seconds];
    }
}
