<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Drivers;

use Fuzeo\Queue\Jobs\Envelope;

final class EnqueuedJob
{
    public function __construct(
        public readonly Envelope $envelope,
        public readonly bool $accepted = true,
        public readonly ?string $duplicateOf = null,
    ) {
    }
}
