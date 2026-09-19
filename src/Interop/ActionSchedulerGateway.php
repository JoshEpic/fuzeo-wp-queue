<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

/**
 * Public Action Scheduler surface only. Absent implementations must not fatal.
 */
interface ActionSchedulerGateway
{
    public function detected(): bool;

    public function version(): ?string;

    public function datastoreAvailable(): bool;

    /**
     * @param array<int|string, mixed> $args
     */
    public function enqueueAsync(string $hook, array $args, string $group): int|string;

    /**
     * @param array<int|string, mixed> $args
     */
    public function scheduleSingle(int $timestamp, string $hook, array $args, string $group): int|string;

    /**
     * @param array<int|string, mixed> $args
     */
    public function scheduleRecurring(int $timestamp, int $intervalSeconds, string $hook, array $args, string $group): int|string;

    /**
     * @param array<string, mixed>|null $args
     */
    public function unschedule(string $hook, ?array $args, string $group): void;

    /**
     * @param array<string, mixed> $query
     * @return list<array<string, mixed>>
     */
    public function query(array $query, int $limit, int $offset): array;

    /**
     * @param array<string, mixed> $query
     */
    public function count(array $query): int;
}
