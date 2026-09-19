<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Retry;

interface Backoff
{
    /**
     * Delay in seconds after the given 1-based attempt has failed.
     */
    public function delaySeconds(int $failedAttempt): int;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
