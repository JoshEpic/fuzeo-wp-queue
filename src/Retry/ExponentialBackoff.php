<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Retry;

final class ExponentialBackoff implements Backoff
{
    public function __construct(
        private readonly int $seconds = 30,
        private readonly int $capSeconds = 3600,
    ) {
        if ($this->seconds < 1) {
            throw new \InvalidArgumentException('Exponential backoff base must be positive.');
        }
    }

    public function delaySeconds(int $failedAttempt): int
    {
        $n = max(1, $failedAttempt);
        if ($n > 16) {
            return $this->capSeconds;
        }
        $delay = $this->seconds * (2 ** ($n - 1));

        return min($this->capSeconds, $delay);
    }

    public function toArray(): array
    {
        return [
            'type' => 'exponential',
            'seconds' => $this->seconds,
            'cap_seconds' => $this->capSeconds,
        ];
    }
}
