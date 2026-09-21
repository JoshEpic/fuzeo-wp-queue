<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Support;

use Fuzeo\Queue\Persistence\Connection;

final class CapturingConnection implements Connection
{
    public string $lastSelectSql = '';

    /** @var list<mixed> */
    public array $lastSelectBindings = [];

    public function prefix(): string
    {
        return 'wp_';
    }

    public function select(string $sql, array $bindings = []): array
    {
        $this->lastSelectSql = $sql;
        $this->lastSelectBindings = $bindings;

        return [];
    }

    public function selectOne(string $sql, array $bindings = []): ?array
    {
        unset($sql, $bindings);

        return null;
    }

    public function execute(string $sql, array $bindings = []): int
    {
        unset($sql, $bindings);

        return 0;
    }

    public function begin(): void
    {
    }

    public function commit(): void
    {
    }

    public function rollBack(): void
    {
    }

    public function ping(): bool
    {
        return true;
    }

    public function reconnect(): void
    {
    }

    public function supportsSkipLocked(): bool
    {
        return true;
    }
}
