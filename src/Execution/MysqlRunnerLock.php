<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Execution;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Locks\DistributedLock;
use Fuzeo\Queue\Persistence\Connection;
use Fuzeo\Queue\Persistence\Schema;
use Fuzeo\Queue\Support\SystemClock;

/**
 * Ownership-token + TTL lock stored in schema meta. Crash cannot pin the lock forever.
 */
final class MysqlRunnerLock implements DistributedLock
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function acquire(string $name, string $owner, int $ttlSeconds): bool
    {
        $now = $this->clock->now()->getTimestamp();
        $expires = $now + max(1, $ttlSeconds);
        $key = $this->key($name);
        $row = $this->connection->selectOne(
            'SELECT `meta_value` FROM ' . $this->table() . ' WHERE `meta_key` = ?',
            [$key]
        );
        $current = is_array($row) ? (string) ($row['meta_value'] ?? '') : '';
        if ($current !== '' && !$this->expired($current, $now) && !str_starts_with($current, $owner . ':')) {
            return false;
        }
        $this->connection->execute(
            'INSERT INTO ' . $this->table() . ' (`meta_key`, `meta_value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `meta_value` = VALUES(`meta_value`)',
            [$key, $owner . ':' . $expires]
        );

        $check = $this->connection->selectOne(
            'SELECT `meta_value` FROM ' . $this->table() . ' WHERE `meta_key` = ?',
            [$key]
        );
        $value = is_array($check) ? (string) ($check['meta_value'] ?? '') : '';

        return str_starts_with($value, $owner . ':');
    }

    public function release(string $name, string $owner): bool
    {
        $key = $this->key($name);
        $row = $this->connection->selectOne(
            'SELECT `meta_value` FROM ' . $this->table() . ' WHERE `meta_key` = ?',
            [$key]
        );
        $current = is_array($row) ? (string) ($row['meta_value'] ?? '') : '';
        if (!str_starts_with($current, $owner . ':')) {
            return false;
        }
        $this->connection->execute('DELETE FROM ' . $this->table() . ' WHERE `meta_key` = ?', [$key]);

        return true;
    }

    public function extend(string $name, string $owner, int $ttlSeconds): bool
    {
        return $this->acquire($name, $owner, $ttlSeconds);
    }

    private function expired(string $value, int $now): bool
    {
        $parts = explode(':', $value, 2);
        $until = isset($parts[1]) && is_numeric($parts[1]) ? (int) $parts[1] : 0;

        return $until <= $now;
    }

    private function key(string $name): string
    {
        return 'lock:' . substr($name, 0, 48);
    }

    private function table(): string
    {
        return Schema::quoteTable($this->connection->prefix(), Schema::META);
    }
}
