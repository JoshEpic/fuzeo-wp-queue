<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

use Fuzeo\Queue\Jobs\DispatchOptions;
use Fuzeo\Queue\Jobs\Job;

/**
 * Consumer-facing async boundary. This is not a queue driver contract.
 */
interface AsyncRuntime
{
    public function name(): RuntimeName;

    public function healthy(): bool;

    public function capabilities(): RuntimeCapabilities;

    public function supports(string $capability): bool;

    public function dispatch(Job $job, ?DispatchOptions $options = null): RuntimeDispatch;

    public function later(\DateTimeInterface|int|string $when, Job $job, ?DispatchOptions $options = null): RuntimeDispatch;

    public function recurring(string $name, Job $job, RecurringSpec $spec, ?DispatchOptions $options = null): RuntimeDispatch;

    /**
     * Adapter-owned pending work on the legacy runtime (not all Action Scheduler actions).
     */
    public function pendingOwnedCount(): int;
}
