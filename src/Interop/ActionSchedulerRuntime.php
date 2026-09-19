<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Contracts\ExecutionContextResolver;
use Fuzeo\Queue\Exceptions\InteropException;
use Fuzeo\Queue\Jobs\DispatchOptions;
use Fuzeo\Queue\Jobs\Job;
use Fuzeo\Queue\Jobs\JobRegistry;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Jobs\QueueName;
use Fuzeo\Queue\Serialization\PayloadSerializer;
use Fuzeo\Queue\Support\Delay;
use Fuzeo\Queue\Support\SystemClock;

final class ActionSchedulerRuntime implements AsyncRuntime
{
    private readonly ActionSchedulerInspector $inspector;

    public function __construct(
        private readonly ActionSchedulerGateway $gateway,
        private readonly JobRegistry $registry,
        private readonly ExecutionContextResolver $context,
        private readonly PayloadSerializer $serializer,
        private readonly Origin $consumer,
        private readonly Clock $clock = new SystemClock(),
        ?ActionSchedulerInspector $inspector = null,
    ) {
        $this->inspector = $inspector ?? new ActionSchedulerInspector($gateway);
    }

    public function name(): RuntimeName
    {
        return RuntimeName::ActionScheduler;
    }

    public function healthy(): bool
    {
        return $this->gateway->detected() && $this->gateway->datastoreAvailable();
    }

    public function capabilities(): RuntimeCapabilities
    {
        return new RuntimeCapabilities(
            dispatch: true,
            delay: true,
            recurring: true,
            recurringCron: false,
            chains: false,
            batches: false,
            idempotency: false,
            uniqueness: false,
            rateLimiting: false,
            persistentWorkers: false,
            cancellation: false,
            visibility: false,
        );
    }

    public function supports(string $capability): bool
    {
        return $this->capabilities()->supports($capability);
    }

    public function dispatch(Job $job, ?DispatchOptions $options = null): RuntimeDispatch
    {
        $id = $this->gateway->enqueueAsync(
            FallbackEnvelope::HOOK,
            $this->args($job, $options),
            FallbackEnvelope::groupFor($this->origin($options)),
        );

        return new RuntimeDispatch(RuntimeName::ActionScheduler, (string) $id, 'action');
    }

    public function later(\DateTimeInterface|int|string $when, Job $job, ?DispatchOptions $options = null): RuntimeDispatch
    {
        $at = Delay::resolve($this->clock, $when);
        $id = $this->gateway->scheduleSingle(
            $at->getTimestamp(),
            FallbackEnvelope::HOOK,
            $this->args($job, $options),
            FallbackEnvelope::groupFor($this->origin($options)),
        );

        return new RuntimeDispatch(RuntimeName::ActionScheduler, (string) $id, 'action');
    }

    public function recurring(string $name, Job $job, RecurringSpec $spec, ?DispatchOptions $options = null): RuntimeDispatch
    {
        unset($name);
        if (!$this->supports('recurring')) {
            throw new InteropException(
                'Action Scheduler fallback supports interval recurring actions only. Cron expressions require Fuzeo Queue.'
            );
        }
        $first = $spec->firstRunAt ?? $this->clock->now();
        $id = $this->gateway->scheduleRecurring(
            $first->getTimestamp(),
            $spec->intervalSeconds,
            FallbackEnvelope::HOOK,
            $this->args($job, $options),
            FallbackEnvelope::groupFor($this->origin($options)),
        );

        return new RuntimeDispatch(RuntimeName::ActionScheduler, (string) $id, 'recurring_action');
    }

    public function pendingOwnedCount(): int
    {
        return $this->inspector->ownedPending(FallbackEnvelope::groupFor($this->consumer));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function args(Job $job, ?DispatchOptions $options): array
    {
        $origin = $this->origin($options);
        $queue = QueueName::normalize($options?->queue ?? QueueName::DEFAULT);
        $context = $options?->context ?? $this->context->current();
        $this->registry->get($job::type());
        $envelope = new FallbackEnvelope(
            $job::type(),
            $job::schemaVersion(),
            $job->payload(),
            $origin,
            $context,
            $queue,
        );

        return [$envelope->toActionArgs($this->serializer)];
    }

    private function origin(?DispatchOptions $options): Origin
    {
        return $options?->origin ?? $this->consumer;
    }
}
