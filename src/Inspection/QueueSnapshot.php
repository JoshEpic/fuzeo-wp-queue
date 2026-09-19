<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Inspection;

final class QueueSnapshot
{
    public function __construct(
        public readonly string $queue,
        public readonly int $pending,
        public readonly int $reserved,
        public readonly int $retrying,
        public readonly int $dead,
        public readonly int $cancelled,
        public readonly ?int $oldestPendingAgeSeconds,
    ) {
    }

    public function depth(): int
    {
        return $this->pending + $this->reserved + $this->retrying;
    }
}
