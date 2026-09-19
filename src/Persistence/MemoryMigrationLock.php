<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Persistence;

use Fuzeo\Queue\Exceptions\SchemaException;

final class MemoryMigrationLock implements MigrationLock
{
    private bool $locked = false;

    public function acquire(int $timeoutSeconds = 30): bool
    {
        unset($timeoutSeconds);
        if ($this->locked) {
            return false;
        }
        $this->locked = true;

        return true;
    }

    public function release(): void
    {
        if (!$this->locked) {
            throw new SchemaException('Migration lock is not held.');
        }
        $this->locked = false;
    }
}
