<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

use Fuzeo\Queue\Jobs\DispatchOptions;
use Fuzeo\Queue\Jobs\Job;
use Fuzeo\Queue\Support\Ulid;

/**
 * Test double. Records which runtime name the test instructed it to impersonate.
 */
final class FakeAsyncRuntime implements AsyncRuntime
{
    /** @var list<array<string, mixed>> */
    public array $dispatched = [];

    /** @var list<array<string, mixed>> */
    public array $delayed = [];

    /** @var list<array<string, mixed>> */
    public array $recurring = [];

    public function __construct(
        private RuntimeName $active = RuntimeName::Fake,
        private RuntimeCapabilities $caps = new RuntimeCapabilities(
            dispatch: true,
            delay: true,
            recurring: true,
            recurringCron: true,
            chains: true,
            batches: true,
            idempotency: true,
            uniqueness: true,
            rateLimiting: true,
            persistentWorkers: true,
            cancellation: true,
            visibility: true,
        ),
        private bool $healthy = true,
        private int $legacyPending = 0,
    ) {
    }

    public function impersonate(RuntimeName $name): self
    {
        $this->active = $name;

        return $this;
    }

    public function withLegacyPending(int $count): self
    {
        $this->legacyPending = $count;

        return $this;
    }

    public function withoutBatches(): self
    {
        $this->caps = new RuntimeCapabilities(
            dispatch: $this->caps->dispatch,
            delay: $this->caps->delay,
            recurring: $this->caps->recurring,
            recurringCron: $this->caps->recurringCron,
            chains: false,
            batches: false,
            idempotency: $this->caps->idempotency,
            uniqueness: $this->caps->uniqueness,
            rateLimiting: $this->caps->rateLimiting,
            persistentWorkers: $this->caps->persistentWorkers,
            cancellation: $this->caps->cancellation,
            visibility: $this->caps->visibility,
        );

        return $this;
    }

    public function name(): RuntimeName
    {
        return $this->active;
    }

    public function healthy(): bool
    {
        return $this->healthy;
    }

    public function capabilities(): RuntimeCapabilities
    {
        return $this->caps;
    }

    public function supports(string $capability): bool
    {
        return $this->caps->supports($capability);
    }

    public function dispatch(Job $job, ?DispatchOptions $options = null): RuntimeDispatch
    {
        $id = Ulid::generate();
        $this->dispatched[] = [
            'runtime' => $this->active->value,
            'job' => $job::class,
            'type' => $job::type(),
            'payload' => $job->payload(),
            'options' => $options,
            'id' => $id,
        ];

        return new RuntimeDispatch($this->active, $id, 'job');
    }

    public function later(\DateTimeInterface|int|string $when, Job $job, ?DispatchOptions $options = null): RuntimeDispatch
    {
        $result = $this->dispatch($job, $options);
        $this->delayed[] = ['when' => $when, 'id' => $result->id];

        return $result;
    }

    public function recurring(string $name, Job $job, RecurringSpec $spec, ?DispatchOptions $options = null): RuntimeDispatch
    {
        $id = 'sched_' . md5($name);
        $this->recurring[] = ['name' => $name, 'job' => $job::class, 'spec' => $spec, 'id' => $id];
        unset($options);

        return new RuntimeDispatch($this->active, $id, 'schedule');
    }

    public function pendingOwnedCount(): int
    {
        return $this->legacyPending;
    }

    public function assertUsed(RuntimeName $name): void
    {
        foreach ($this->dispatched as $row) {
            if (($row['runtime'] ?? '') === $name->value) {
                return;
            }
        }
        throw new \Fuzeo\Queue\Exceptions\InteropException('Expected dispatches on runtime ' . $name->value . '.');
    }

    public function assertDispatched(string $jobClass): void
    {
        foreach ($this->dispatched as $row) {
            if (($row['job'] ?? '') === $jobClass) {
                return;
            }
        }
        throw new \Fuzeo\Queue\Exceptions\InteropException('Expected job ' . $jobClass . ' to be dispatched.');
    }
}
