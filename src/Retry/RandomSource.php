<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Retry;

interface RandomSource
{
    /**
     * @return float Number in [0.0, 1.0]
     */
    public function nextFloat(): float;
}
