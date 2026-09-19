<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Jobs;

use Fuzeo\Queue\Drivers\CancelsJobs;

/**
 * Runtime context for a reserved job. Cancellation is cooperative.
 */
final class JobContext
{
    public function __construct(
        public readonly Envelope $envelope,
        private readonly ?CancelsJobs $control = null,
    ) {
    }

    public function isCancellationRequested(): bool
    {
        return $this->control?->isCancellationRequested($this->envelope->jobId) ?? false;
    }
}
