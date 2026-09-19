<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Drivers;

use Fuzeo\Queue\Jobs\JobState;

final class CancelResult
{
    public const CANCELLED = 'cancelled';

    public const CANCEL_REQUESTED = 'cancel_requested';

    public const UNCHANGED = 'unchanged';

    public const NOT_FOUND = 'not_found';

    public function __construct(
        public readonly string $outcome,
        public readonly ?JobState $state,
        public readonly string $jobId,
    ) {
    }

    public function isTerminalCancel(): bool
    {
        return $this->outcome === self::CANCELLED;
    }
}
