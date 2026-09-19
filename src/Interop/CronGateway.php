<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

interface CronGateway
{
    public function eventsPresent(): bool;

    public function automaticSpawningDisabled(): bool;

    /**
     * @return array<int, array<string, array<string, array<string, mixed>>>>
     */
    public function cronArray(): array;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function schedules(): array;

    /**
     * @param list<mixed> $args
     */
    public function unschedule(int $timestamp, string $hook, array $args): bool;

    /**
     * @param list<mixed> $args
     */
    public function scheduleSingle(int $timestamp, string $hook, array $args): bool;

    /**
     * @param list<mixed> $args
     */
    public function scheduleRecurring(int $timestamp, string $recurrence, string $hook, array $args): bool;
}
