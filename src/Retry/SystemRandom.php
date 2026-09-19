<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Retry;

final class SystemRandom implements RandomSource
{
    public function nextFloat(): float
    {
        return random_int(0, 1_000_000) / 1_000_000;
    }
}
