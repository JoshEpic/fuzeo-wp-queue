<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

use Fuzeo\Queue\Core\QueueManager;
use Fuzeo\Queue\Exceptions\RuntimeUnavailableException;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Runtime\Coordinator;

final class RuntimeResolver
{
    public function __construct(
        private readonly ?QueueManager $queue,
        private readonly ActionSchedulerGateway $actions,
        private readonly Origin $consumer,
    ) {
    }

    public function select(RuntimePolicy $policy = RuntimePolicy::PreferQueue): RuntimeSelection
    {
        $available = $this->queueAvailable();
        $healthy = $this->queueHealthy();
        if ($healthy) {
            return new RuntimeSelection(
                RuntimeName::FuzeoQueue,
                RuntimeName::FuzeoQueue,
                $this->actions->detected() ? RuntimeName::ActionScheduler : null,
                'Fuzeo Queue is available and healthy.',
                true,
                true,
            );
        }
        if ($policy === RuntimePolicy::RequireQueue) {
            return new RuntimeSelection(
                RuntimeName::Unavailable,
                RuntimeName::FuzeoQueue,
                null,
                'Fuzeo Queue is required and is not healthy.',
                false,
                $available,
            );
        }
        if ($this->actions->detected() && $this->actions->datastoreAvailable()) {
            return new RuntimeSelection(
                RuntimeName::ActionScheduler,
                RuntimeName::FuzeoQueue,
                RuntimeName::ActionScheduler,
                $available
                    ? 'Fuzeo Queue is installed but not healthy; new work uses Action Scheduler fallback.'
                    : 'Fuzeo Queue is not available; new work uses Action Scheduler fallback.',
                false,
                $available,
            );
        }

        return new RuntimeSelection(
            RuntimeName::Unavailable,
            RuntimeName::FuzeoQueue,
            null,
            'Neither Fuzeo Queue nor Action Scheduler is available.',
            false,
            $available,
        );
    }

    public function resolve(RuntimePolicy $policy = RuntimePolicy::PreferQueue): AsyncRuntime
    {
        $selection = $this->select($policy);
        if ($selection->active === RuntimeName::FuzeoQueue && $this->queue !== null) {
            return new FuzeoQueueRuntime($this->queue);
        }
        if ($selection->active === RuntimeName::ActionScheduler && $this->queue !== null) {
            return new ActionSchedulerRuntime(
                $this->actions,
                $this->queue->jobs(),
                $this->queue->context(),
                $this->queue->serializer(),
                $this->consumer,
                $this->queue->clock(),
                new ActionSchedulerInspector($this->actions),
            );
        }
        if ($selection->active === RuntimeName::ActionScheduler) {
            throw new RuntimeUnavailableException('Action Scheduler fallback requires a booted Fuzeo Queue registry for job types.');
        }
        if ($policy === RuntimePolicy::RequireQueue) {
            throw new RuntimeUnavailableException('Fuzeo Queue is required for this workload and is not healthy.');
        }

        return new UnavailableRuntime();
    }

    /**
     * @return array<string, mixed>
     */
    public function transition(RuntimePolicy $policy = RuntimePolicy::PreferQueue): array
    {
        $selection = $this->select($policy);
        $legacy = 0;
        if ($this->actions->detected()) {
            $legacy = (new ActionSchedulerInspector($this->actions))->ownedPending(FallbackEnvelope::groupFor($this->consumer));
        }

        return [
            'preferred_runtime' => $selection->preferred->value,
            'active_runtime' => $selection->active->value,
            'legacy_runtime' => RuntimeName::ActionScheduler->value,
            'legacy_pending' => $legacy,
            'fully_transitioned' => $selection->active === RuntimeName::FuzeoQueue && $legacy === 0,
            'reason' => $selection->reason,
            'execution_mode' => $this->executionMode(),
        ];
    }

    private function queueAvailable(): bool
    {
        return $this->queue !== null || Coordinator::isBooted();
    }

    private function queueHealthy(): bool
    {
        if ($this->queue === null) {
            return false;
        }
        try {
            return $this->queue->driver()->health()->ok;
        } catch (\Throwable) {
            return false;
        }
    }

    private function executionMode(): string
    {
        if ($this->queue === null) {
            return 'none';
        }
        try {
            return $this->queue->execution()->mode()->value;
        } catch (\Throwable) {
            return 'none';
        }
    }
}
