<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

use Fuzeo\Queue\Exceptions\InteropException;

final class RecurringSpec
{
    public function __construct(
        public readonly int $intervalSeconds,
        public readonly ?\DateTimeImmutable $firstRunAt = null,
        public readonly string $timezone = 'UTC',
    ) {
        if ($this->intervalSeconds < 1) {
            throw new InteropException('Recurring interval must be a positive number of seconds.');
        }
    }
}
