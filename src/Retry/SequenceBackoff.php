<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Retry;

final class SequenceBackoff implements Backoff
{
    /** @var list<int> */
    private array $seconds;

    /**
     * @param list<int> $seconds
     */
    public function __construct(array $seconds)
    {
        if ($seconds === []) {
            throw new \InvalidArgumentException('Sequence backoff requires at least one delay.');
        }
        foreach ($seconds as $value) {
            if ($value < 0) {
                throw new \InvalidArgumentException('Sequence backoff values cannot be negative.');
            }
        }
        $this->seconds = array_values($seconds);
    }

    public function delaySeconds(int $failedAttempt): int
    {
        $index = max(1, $failedAttempt) - 1;
        if ($index >= count($this->seconds)) {
            return $this->seconds[array_key_last($this->seconds)];
        }

        return $this->seconds[$index];
    }

    public function toArray(): array
    {
        return ['type' => 'sequence', 'seconds' => $this->seconds];
    }
}
