<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Deployment;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Runtime\ProcessLifecycle;
use Fuzeo\Queue\Runtime\RecycleReason;
use Fuzeo\Queue\Support\SystemClock;

/**
 * Observes durable restart/drain/generation tokens without treating a boot-time
 * restart generation as a reason to exit.
 */
final class DeploymentWatch
{
    private bool $allowReserve = true;

    public function __construct(
        private readonly DeploymentStore $store,
        private readonly DeploymentGeneration $generation,
        private readonly string $bootRestartGeneration,
        private readonly string $bootDrainGeneration,
        private readonly string $bootDeploymentGeneration,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public static function boot(
        DeploymentStore $store,
        DeploymentGeneration $generation,
        Clock $clock = new SystemClock(),
    ): self {
        $snap = $store->snapshot();

        return new self(
            $store,
            $generation,
            $snap->restartGeneration,
            $snap->drainGeneration,
            $generation->current(),
            $clock,
        );
    }

    public function bootRestartGeneration(): string
    {
        return $this->bootRestartGeneration;
    }

    public function bootDeploymentGeneration(): string
    {
        return $this->bootDeploymentGeneration;
    }

    public function inspect(ProcessLifecycle $lifecycle): void
    {
        $snap = $this->store->snapshot();
        $now = $this->clock->now();

        if ($snap->restartGeneration !== '' && $snap->restartGeneration !== $this->bootRestartGeneration) {
            $this->allowReserve = false;
            $lifecycle->request(RecycleReason::RestartRequested);

            return;
        }

        $current = $this->generation->current();
        if ($current !== $this->bootDeploymentGeneration) {
            $this->allowReserve = false;
            $lifecycle->request(RecycleReason::GenerationChanged);

            return;
        }

        if ($snap->isMaintenanceActive($now)) {
            $this->allowReserve = false;

            return;
        }

        if ($snap->drainGeneration !== '') {
            $this->allowReserve = false;
            if ($snap->drainGeneration !== $this->bootDrainGeneration) {
                $lifecycle->request(RecycleReason::Drain);
            }

            return;
        }

        $this->allowReserve = $lifecycle->reason() === RecycleReason::None;
    }

    public function mayReserve(): bool
    {
        return $this->allowReserve;
    }
}
