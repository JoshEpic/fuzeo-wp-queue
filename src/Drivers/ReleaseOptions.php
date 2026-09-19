<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Drivers;

final class ReleaseOptions
{
    public function __construct(
        public readonly ?\DateTimeImmutable $availableAt = null,
        public readonly int $delaySeconds = 0,
    ) {
        if ($this->delaySeconds < 0) {
            throw new \Fuzeo\Queue\Exceptions\QueueException('delay_seconds cannot be negative.');
        }
    }
}
