<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Jobs;

/**
 * Outcome of enqueue. Duplicate unique jobs are not an exceptional failure.
 */
final class DispatchResult
{
    public function __construct(
        public readonly bool $accepted,
        public readonly Envelope $envelope,
        public readonly ?string $duplicateOf = null,
    ) {
    }

    public function jobId(): string
    {
        return $this->envelope->jobId;
    }
}
