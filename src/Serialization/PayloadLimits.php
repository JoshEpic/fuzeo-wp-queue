<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Serialization;

final class PayloadLimits
{
    public function __construct(
        public readonly int $maxBytes = 262144,
        public readonly int $maxDepth = 32,
    ) {
        if ($this->maxBytes < 1 || $this->maxDepth < 1) {
            throw new \InvalidArgumentException('Payload limits must be positive.');
        }
    }
}
