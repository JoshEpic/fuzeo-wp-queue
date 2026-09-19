<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Persistence;

use Fuzeo\Queue\Exceptions\SchemaException;

final class MysqlAdvisoryLock implements MigrationLock
{
    private bool $held = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $name = Schema::LOCK,
        private readonly int $timeoutSeconds = 30,
    ) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $this->name) || strlen($this->name) > 64) {
            throw new SchemaException('Invalid advisory lock name.');
        }
    }

    public function acquire(int $timeoutSeconds = 30): bool
    {
        $wait = $timeoutSeconds > 0 ? $timeoutSeconds : $this->timeoutSeconds;
        $row = $this->connection->selectOne('SELECT GET_LOCK(?, ?) AS `locked`', [$this->name, $wait]);
        $locked = $row['locked'] ?? 0;
        $this->held = (int) $locked === 1;

        return $this->held;
    }

    public function release(): void
    {
        if (!$this->held) {
            throw new SchemaException('Migration lock is not held.');
        }
        $this->connection->selectOne('SELECT RELEASE_LOCK(?) AS `released`', [$this->name]);
        $this->held = false;
    }
}
