<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Deployment;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Exceptions\DriverException;
use Fuzeo\Queue\Support\SystemClock;

final class MemoryDeploymentStore implements DeploymentStore
{
    private DeploymentState $state;

    private bool $locked = false;

    public function __construct(
        ?DeploymentState $state = null,
        private readonly Clock $clock = new SystemClock(),
    ) {
        $this->state = $state ?? new DeploymentState();
    }

    public function snapshot(): DeploymentState
    {
        return $this->state;
    }

    public function requestRestart(string $generation, \DateTimeImmutable $at): void
    {
        $this->withLock(function () use ($generation, $at): void {
            $this->state = new DeploymentState(
                restartGeneration: $generation,
                restartRequestedAt: $at,
                drainGeneration: $this->state->drainGeneration,
                drainRequestedAt: $this->state->drainRequestedAt,
                maintenanceOwner: $this->state->maintenanceOwner,
                maintenanceExpiresAt: $this->state->maintenanceExpiresAt,
                maintenanceReason: $this->state->maintenanceReason,
                currentGeneration: $this->state->currentGeneration,
            );
        });
    }

    public function requestDrain(string $generation, \DateTimeImmutable $at): void
    {
        $this->withLock(function () use ($generation, $at): void {
            $this->state = new DeploymentState(
                restartGeneration: $this->state->restartGeneration,
                restartRequestedAt: $this->state->restartRequestedAt,
                drainGeneration: $generation,
                drainRequestedAt: $at,
                maintenanceOwner: $this->state->maintenanceOwner,
                maintenanceExpiresAt: $this->state->maintenanceExpiresAt,
                maintenanceReason: $this->state->maintenanceReason,
                currentGeneration: $this->state->currentGeneration,
            );
        });
    }

    public function cancelDrain(): void
    {
        $this->withLock(function (): void {
            $this->state = new DeploymentState(
                restartGeneration: $this->state->restartGeneration,
                restartRequestedAt: $this->state->restartRequestedAt,
                drainGeneration: '',
                drainRequestedAt: null,
                maintenanceOwner: $this->state->maintenanceOwner,
                maintenanceExpiresAt: $this->state->maintenanceExpiresAt,
                maintenanceReason: $this->state->maintenanceReason,
                currentGeneration: $this->state->currentGeneration,
            );
        });
    }

    public function enterMaintenance(string $owner, \DateTimeImmutable $expiresAt, string $reason): bool
    {
        return $this->withLock(function () use ($owner, $expiresAt, $reason): bool {
            $now = $this->clock->now();
            if ($this->state->isMaintenanceActive($now) && $this->state->maintenanceOwner !== $owner) {
                return false;
            }
            $this->state = new DeploymentState(
                restartGeneration: $this->state->restartGeneration,
                restartRequestedAt: $this->state->restartRequestedAt,
                drainGeneration: $this->state->drainGeneration,
                drainRequestedAt: $this->state->drainRequestedAt,
                maintenanceOwner: $owner,
                maintenanceExpiresAt: $expiresAt,
                maintenanceReason: $reason,
                currentGeneration: $this->state->currentGeneration,
            );

            return true;
        });
    }

    public function releaseMaintenance(string $owner): bool
    {
        return $this->withLock(function () use ($owner): bool {
            if ($this->state->maintenanceOwner !== $owner) {
                return false;
            }
            $this->state = new DeploymentState(
                restartGeneration: $this->state->restartGeneration,
                restartRequestedAt: $this->state->restartRequestedAt,
                drainGeneration: $this->state->drainGeneration,
                drainRequestedAt: $this->state->drainRequestedAt,
                currentGeneration: $this->state->currentGeneration,
            );

            return true;
        });
    }

    public function setCurrentGeneration(string $generation): void
    {
        $this->withLock(function () use ($generation): void {
            $this->state = new DeploymentState(
                restartGeneration: $this->state->restartGeneration,
                restartRequestedAt: $this->state->restartRequestedAt,
                drainGeneration: $this->state->drainGeneration,
                drainRequestedAt: $this->state->drainRequestedAt,
                maintenanceOwner: $this->state->maintenanceOwner,
                maintenanceExpiresAt: $this->state->maintenanceExpiresAt,
                maintenanceReason: $this->state->maintenanceReason,
                currentGeneration: $generation,
            );
        });
    }

    public function withLock(callable $callback): mixed
    {
        if ($this->locked) {
            throw new DriverException('Deployment lock is already held.');
        }
        $this->locked = true;
        try {
            return $callback();
        } finally {
            $this->locked = false;
        }
    }
}
