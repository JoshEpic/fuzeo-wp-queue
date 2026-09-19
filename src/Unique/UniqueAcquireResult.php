<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Unique;

final class UniqueAcquireResult
{
    public function __construct(
        public readonly bool $acquired,
        public readonly string $jobId,
    ) {
    }
}
