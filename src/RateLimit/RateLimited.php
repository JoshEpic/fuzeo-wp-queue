<?php

declare(strict_types=1);

namespace Fuzeo\Queue\RateLimit;

/**
 * Optional job contract. Copied into envelope metadata at dispatch.
 */
interface RateLimited
{
    public function rateLimit(): RateLimit;
}
