<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Retry;

/**
 * Optional job contract. Policy is copied into envelope metadata at dispatch.
 */
interface Retryable
{
    public function retryPolicy(): RetryPolicy;
}
