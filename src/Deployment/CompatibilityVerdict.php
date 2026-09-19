<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Deployment;

final class CompatibilityVerdict
{
    /**
     * @param list<string> $reasons
     */
    public function __construct(
        public readonly bool $canBoot,
        public readonly bool $canDispatch,
        public readonly bool $canReserve,
        public readonly bool $mustRecycle,
        public readonly bool $mustMigrate,
        public readonly string $primaryReason,
        public readonly array $reasons = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'can_boot' => $this->canBoot,
            'can_dispatch' => $this->canDispatch,
            'can_reserve' => $this->canReserve,
            'must_recycle' => $this->mustRecycle,
            'must_migrate' => $this->mustMigrate,
            'reason' => $this->primaryReason,
            'reasons' => $this->reasons,
        ];
    }
}
