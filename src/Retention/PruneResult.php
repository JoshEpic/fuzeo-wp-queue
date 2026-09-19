<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Retention;

final class PruneResult
{
    public function __construct(
        public readonly int $completedDeleted,
        public readonly int $deadDeleted,
        public readonly int $attemptsDeleted,
    ) {
    }

    public function total(): int
    {
        return $this->completedDeleted + $this->deadDeleted + $this->attemptsDeleted;
    }
}
