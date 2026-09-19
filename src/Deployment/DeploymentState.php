<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Deployment;

final class DeploymentState
{
    public function __construct(
        public readonly string $restartGeneration = '',
        public readonly ?\DateTimeImmutable $restartRequestedAt = null,
        public readonly string $drainGeneration = '',
        public readonly ?\DateTimeImmutable $drainRequestedAt = null,
        public readonly string $maintenanceOwner = '',
        public readonly ?\DateTimeImmutable $maintenanceExpiresAt = null,
        public readonly string $maintenanceReason = '',
        public readonly string $currentGeneration = '',
    ) {
    }

    public function isDraining(): bool
    {
        return $this->drainGeneration !== '';
    }

    public function isMaintenanceActive(\DateTimeImmutable $now): bool
    {
        if ($this->maintenanceOwner === '' || $this->maintenanceExpiresAt === null) {
            return false;
        }

        return $this->maintenanceExpiresAt->getTimestamp() > $now->getTimestamp();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(\DateTimeImmutable $now): array
    {
        return [
            'restart_generation' => $this->restartGeneration,
            'restart_requested_at' => $this->restartRequestedAt?->format(\DateTimeInterface::ATOM),
            'drain_generation' => $this->drainGeneration,
            'drain_requested_at' => $this->drainRequestedAt?->format(\DateTimeInterface::ATOM),
            'draining' => $this->isDraining(),
            'maintenance' => $this->isMaintenanceActive($now),
            'maintenance_owner' => $this->maintenanceOwner !== '' ? substr($this->maintenanceOwner, 0, 8) : '',
            'maintenance_expires_at' => $this->maintenanceExpiresAt?->format(\DateTimeInterface::ATOM),
            'maintenance_reason' => $this->maintenanceReason,
            'current_generation' => $this->currentGeneration,
        ];
    }
}
