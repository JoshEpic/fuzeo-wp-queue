<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Retry;

final class LinearBackoff implements Backoff
{
    public function __construct(private readonly int $seconds = 30)
    {
        if ($this->seconds < 1) {
            throw new \InvalidArgumentException('Linear backoff base must be positive.');
        }
    }

    public function delaySeconds(int $failedAttempt): int
    {
        $n = max(1, $failedAttempt);

        return $this->seconds * $n;
    }

    public function toArray(): array
    {
        return ['type' => 'linear', 'seconds' => $this->seconds];
    }
}
