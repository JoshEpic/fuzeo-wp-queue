<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Persistence;

final class MigrationResult
{
    /**
     * @param list<int> $applied
     */
    public function __construct(
        public readonly int $fromVersion,
        public readonly int $toVersion,
        public readonly array $applied,
        public readonly bool $lockedOut = false,
    ) {
    }
}
