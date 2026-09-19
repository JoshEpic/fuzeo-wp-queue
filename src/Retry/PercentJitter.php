<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Retry;

final class PercentJitter
{
    public function __construct(
        private readonly int $percent,
        private readonly RandomSource $random = new SystemRandom(),
    ) {
        if ($this->percent < 0 || $this->percent > 100) {
            throw new \InvalidArgumentException('Jitter percent must be 0-100.');
        }
    }

    public function apply(int $seconds): int
    {
        if ($this->percent === 0 || $seconds <= 0) {
            return max(0, $seconds);
        }
        $span = $seconds * ($this->percent / 100);
        $delta = (int) round(($this->random->nextFloat() * 2 - 1) * $span);

        return max(0, $seconds + $delta);
    }

    public function percent(): int
    {
        return $this->percent;
    }
}
