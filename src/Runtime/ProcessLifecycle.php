<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Runtime;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Deployment\DeploymentWatch;
use Fuzeo\Queue\Support\SystemClock;
use Fuzeo\Queue\Worker\WorkerOptions;

/**
 * Shared recycle, memory, generation, and GC decisions for workers and schedulers.
 */
final class ProcessLifecycle
{
    private RecycleReason $reason = RecycleReason::None;

    private int $jobsSinceGenerationCheck = 0;

    private int $jobsSinceGc = 0;

    private string $cachedGeneration;

    private readonly \DateTimeImmutable $startedAt;

    public function __construct(
        private readonly WorkerOptions $options,
        private readonly GenerationSource $generation,
        private readonly MemoryMonitor $memory,
        private readonly RuntimeLogger $logger = new NullLogger(),
        private readonly Clock $clock = new SystemClock(),
        ?string $bootGeneration = null,
        ?\DateTimeImmutable $startedAt = null,
        private readonly ?DeploymentWatch $deployment = null,
    ) {
        $this->cachedGeneration = $bootGeneration ?? $this->generation->current();
        $this->startedAt = $startedAt ?? $this->clock->now();
    }

    public function bootGeneration(): string
    {
        return $this->cachedGeneration;
    }

    public function reason(): RecycleReason
    {
        return $this->reason;
    }

    public function request(RecycleReason $reason): void
    {
        if ($this->reason === RecycleReason::None) {
            $this->reason = $reason;
            $this->logger->log('worker.recycle', ['reason' => $reason->value]);
        }
    }

    public function afterJob(): void
    {
        $this->jobsSinceGenerationCheck++;
        $this->jobsSinceGc++;
        if ($this->options->gcInterval > 0 && $this->jobsSinceGc >= $this->options->gcInterval) {
            $this->jobsSinceGc = 0;
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }
        if ($this->options->generationCheckInterval > 0 && $this->jobsSinceGenerationCheck >= $this->options->generationCheckInterval) {
            $this->jobsSinceGenerationCheck = 0;
            $this->refreshGeneration();
        }
        $this->deployment?->inspect($this);
        if ($this->memory->exceeds($this->options->memoryBytes)) {
            $this->logger->log('runtime.memory_threshold', $this->memory->snapshot());
            $this->request(RecycleReason::Memory);
        }
    }

    public function shouldExit(int $processed, bool $stopRequested): bool
    {
        if ($this->reason !== RecycleReason::None) {
            return true;
        }
        if ($stopRequested) {
            $this->request(RecycleReason::Signal);

            return true;
        }
        if ($this->options->maxJobs > 0 && $processed >= $this->options->maxJobs) {
            $this->request(RecycleReason::MaxJobs);

            return true;
        }
        if ($this->options->maxRuntimeSeconds > 0) {
            $elapsed = $this->clock->now()->getTimestamp() - $this->startedAt->getTimestamp();
            if ($elapsed >= $this->options->maxRuntimeSeconds) {
                $this->request(RecycleReason::MaxRuntime);

                return true;
            }
        }
        if ($this->memory->exceeds($this->options->memoryBytes)) {
            $this->logger->log('runtime.memory_threshold', $this->memory->snapshot());
            $this->request(RecycleReason::Memory);

            return true;
        }
        $this->deployment?->inspect($this);
        if ($this->reason !== RecycleReason::None) {
            return true;
        }

        return false;
    }

    public function refreshGeneration(): void
    {
        $current = $this->generation->current();
        if ($current !== $this->cachedGeneration) {
            $this->logger->log('runtime.generation_changed', [
                'from' => substr($this->cachedGeneration, 0, 12),
                'to' => substr($current, 0, 12),
            ]);
            $this->request(RecycleReason::GenerationChanged);
        }
        $this->deployment?->inspect($this);
    }

    public function mayReserve(): bool
    {
        if ($this->reason !== RecycleReason::None) {
            return false;
        }
        $this->deployment?->inspect($this);
        if ($this->deployment !== null) {
            return $this->deployment->mayReserve();
        }

        return true;
    }

    public function deployment(): ?DeploymentWatch
    {
        return $this->deployment;
    }

    public function startedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function memory(): MemoryMonitor
    {
        return $this->memory;
    }
}
