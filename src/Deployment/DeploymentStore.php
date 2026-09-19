<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Deployment;

/**
 * Backend-neutral control-plane state for deploy/restart/drain/maintenance.
 * Not a queue transport and not a release-management database.
 */
interface DeploymentStore
{
    public function snapshot(): DeploymentState;

    public function requestRestart(string $generation, \DateTimeImmutable $at): void;

    public function requestDrain(string $generation, \DateTimeImmutable $at): void;

    public function cancelDrain(): void;

    public function enterMaintenance(string $owner, \DateTimeImmutable $expiresAt, string $reason): bool;

    public function releaseMaintenance(string $owner): bool;

    public function setCurrentGeneration(string $generation): void;

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function withLock(callable $callback): mixed;
}
