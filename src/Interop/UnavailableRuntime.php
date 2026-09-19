<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

use Fuzeo\Queue\Exceptions\RuntimeUnavailableException;
use Fuzeo\Queue\Jobs\DispatchOptions;
use Fuzeo\Queue\Jobs\Job;

final class UnavailableRuntime implements AsyncRuntime
{
    public function name(): RuntimeName
    {
        return RuntimeName::Unavailable;
    }

    public function healthy(): bool
    {
        return false;
    }

    public function capabilities(): RuntimeCapabilities
    {
        return new RuntimeCapabilities(
            dispatch: false,
            delay: false,
            recurring: false,
        );
    }

    public function supports(string $capability): bool
    {
        return false;
    }

    public function dispatch(Job $job, ?DispatchOptions $options = null): RuntimeDispatch
    {
        unset($job, $options);
        throw new RuntimeUnavailableException('No async runtime is available (Fuzeo Queue is unhealthy and Action Scheduler is absent).');
    }

    public function later(\DateTimeInterface|int|string $when, Job $job, ?DispatchOptions $options = null): RuntimeDispatch
    {
        unset($when, $job, $options);
        throw new RuntimeUnavailableException('No async runtime is available.');
    }

    public function recurring(string $name, Job $job, RecurringSpec $spec, ?DispatchOptions $options = null): RuntimeDispatch
    {
        unset($name, $job, $spec, $options);
        throw new RuntimeUnavailableException('No async runtime is available.');
    }

    public function pendingOwnedCount(): int
    {
        return 0;
    }
}
