<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Deployment;

use Fuzeo\Queue\Persistence\SchemaOwner;
use Fuzeo\Queue\Redis\RedisScripts;
use Fuzeo\Queue\Runtime\PackageInfo;

/**
 * Single compatibility evaluator for runtime, schema, Lua, and deployment state.
 */
final class RuntimeCompatibility
{
    public function __construct(
        private readonly int $loadedSchema = SchemaOwner::CURRENT_VERSION,
        private readonly int $storedSchema = SchemaOwner::CURRENT_VERSION,
        private readonly int $targetSchema = SchemaOwner::CURRENT_VERSION,
        private readonly int $loadedSeries = PackageInfo::COMPATIBILITY_SERIES,
        private readonly int $requiredSeries = PackageInfo::COMPATIBILITY_SERIES,
        private readonly string $loadedLua = RedisScripts::VERSION,
        private readonly string $storedLua = RedisScripts::VERSION,
        private readonly bool $enforceLua = false,
        private readonly bool $driverOk = true,
        private readonly bool $exactSchema = true,
        private readonly DeploymentState $state = new DeploymentState(),
        private readonly \DateTimeImmutable $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        private readonly string $loadedPackage = PackageInfo::VERSION,
        private readonly string $highestCandidateVersion = '',
    ) {
    }

    public function evaluate(): CompatibilityVerdict
    {
        $reasons = [];
        $canBoot = true;
        $canDispatch = true;
        $canReserve = true;
        $mustRecycle = false;
        $mustMigrate = false;
        $primary = ReadinessReason::Ready->value;

        if (!$this->driverOk) {
            $canBoot = false;
            $canDispatch = false;
            $canReserve = false;
            $primary = ReadinessReason::DriverUnavailable->value;
            $reasons[] = 'Queue backend is unavailable.';
        }

        if ($this->loadedSeries !== $this->requiredSeries) {
            $canBoot = false;
            $canDispatch = false;
            $canReserve = false;
            $mustRecycle = true;
            $primary = ReadinessReason::RuntimeIncompatible->value;
            $reasons[] = 'Compatibility series mismatch.';
        }

        if ($this->exactSchema && $this->storedSchema !== $this->loadedSchema) {
            $canReserve = false;
            if ($this->storedSchema < $this->targetSchema) {
                $mustMigrate = true;
                $canDispatch = $this->storedSchema === $this->loadedSchema;
                $primary = $this->state->isMaintenanceActive($this->now)
                    ? ReadinessReason::MigrationInProgress->value
                    : ReadinessReason::SchemaMismatch->value;
                $reasons[] = 'Stored schema ' . $this->storedSchema . ' does not match runtime ' . $this->loadedSchema . '.';
            } else {
                $canDispatch = false;
                $mustRecycle = true;
                $canBoot = false;
                $primary = ReadinessReason::SchemaMismatch->value;
                $reasons[] = 'Runtime schema ' . $this->loadedSchema . ' cannot operate stored schema ' . $this->storedSchema . '.';
            }
        }

        if ($this->enforceLua && $this->storedLua !== '' && $this->storedLua !== $this->loadedLua) {
            $canReserve = false;
            $mustRecycle = true;
            $primary = ReadinessReason::RuntimeIncompatible->value;
            $reasons[] = 'Redis Lua script version mismatch.';
        }

        if ($this->state->isMaintenanceActive($this->now)) {
            $canReserve = false;
            $canDispatch = false;
            $primary = ReadinessReason::MigrationInProgress->value;
            $reasons[] = 'Deployment maintenance is active: ' . $this->state->maintenanceReason;
        }

        if ($this->state->isDraining()) {
            $canReserve = false;
            if ($primary === ReadinessReason::Ready->value) {
                $primary = ReadinessReason::Draining->value;
            }
            $reasons[] = 'Fleet is draining.';
        }

        if (
            $this->highestCandidateVersion !== ''
            && $this->highestCandidateVersion !== $this->loadedPackage
        ) {
            $reasons[] = 'Loaded class version is ' . $this->loadedPackage
                . '; highest bundled candidate metadata is ' . $this->highestCandidateVersion
                . '. Deployment must not claim the candidate version is running.';
        }

        if ($reasons === []) {
            $reasons[] = 'ready';
        }

        return new CompatibilityVerdict(
            $canBoot,
            $canDispatch,
            $canReserve,
            $mustRecycle,
            $mustMigrate,
            $primary,
            $reasons,
        );
    }
}
