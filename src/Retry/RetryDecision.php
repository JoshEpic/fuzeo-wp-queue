<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Retry;

use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\JobState;

final class RetryDecision
{
    public function __construct(
        public readonly JobState $nextState,
        public readonly ?\DateTimeImmutable $availableAt,
        public readonly bool $willRetry,
        public readonly string $reason,
        public readonly ?string $terminalReason,
    ) {
    }

    public function isDead(): bool
    {
        return $this->nextState === JobState::Dead;
    }
}
