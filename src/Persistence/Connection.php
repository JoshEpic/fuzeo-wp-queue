<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Persistence;

/**
 * Database access used by the MySQL driver and schema migrations.
 */
interface Connection
{
    public function prefix(): string;

    /**
     * @param list<mixed> $bindings
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array;

    /**
     * @param list<mixed> $bindings
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $bindings = []): ?array;

    /**
     * @param list<mixed> $bindings
     */
    public function execute(string $sql, array $bindings = []): int;

    public function begin(): void;

    public function commit(): void;

    public function rollBack(): void;

    public function ping(): bool;

    /**
     * Reconnect after a dropped connection. In-flight transaction state is lost.
     */
    public function reconnect(): void;

    public function supportsSkipLocked(): bool;
}
