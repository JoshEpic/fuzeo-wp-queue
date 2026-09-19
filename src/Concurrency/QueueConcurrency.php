<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Concurrency;

final class QueueConcurrency
{
    public function __construct(private readonly int $max)
    {
        if ($this->max < 1) {
            throw new \Fuzeo\Queue\Exceptions\ConfigurationException('Concurrency max must be at least 1.');
        }
    }

    public function forQueue(string $queue): ConcurrencyLimit
    {
        return new ConcurrencyLimit([$queue => $this->max]);
    }

    public function max(): int
    {
        return $this->max;
    }
}
