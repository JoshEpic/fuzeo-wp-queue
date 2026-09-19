<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Deployment;

final class ReadinessReport
{
    /**
     * @param list<string> $reasons
     * @param array<string, mixed> $details
     */
    public function __construct(
        public readonly bool $ready,
        public readonly string $reason,
        public readonly array $reasons,
        public readonly bool $canDispatch,
        public readonly bool $canReserve,
        public readonly int $exitCode,
        public readonly array $details = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ready' => $this->ready,
            'reason' => $this->reason,
            'reasons' => $this->reasons,
            'can_dispatch' => $this->canDispatch,
            'can_reserve' => $this->canReserve,
            'exit_code' => $this->exitCode,
            'details' => $this->details,
        ];
    }
}
