<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

final class RuntimeSelection
{
    public function __construct(
        public readonly RuntimeName $active,
        public readonly RuntimeName $preferred,
        public readonly ?RuntimeName $fallback,
        public readonly string $reason,
        public readonly bool $queueHealthy,
        public readonly bool $queueAvailable,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'active' => $this->active->value,
            'preferred' => $this->preferred->value,
            'fallback' => $this->fallback?->value,
            'reason' => $this->reason,
            'queue_healthy' => $this->queueHealthy,
            'queue_available' => $this->queueAvailable,
        ];
    }
}
