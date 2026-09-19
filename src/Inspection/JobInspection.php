<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Inspection;

use Fuzeo\Queue\Jobs\Envelope;

final class JobInspection
{
    public function __construct(
        public readonly Envelope $envelope,
        public readonly ?string $workerId = null,
        public readonly ?\DateTimeImmutable $reservedAt = null,
        public readonly ?\DateTimeImmutable $leaseExpiresAt = null,
        public readonly bool $cancelRequested = false,
        public readonly ?string $failureClass = null,
        public readonly ?string $failureMessage = null,
    ) {
    }
}
