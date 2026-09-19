<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Persistence;

interface MigrationLock
{
    public function acquire(int $timeoutSeconds = 30): bool;

    public function release(): void;
}
