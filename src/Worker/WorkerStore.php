<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Worker;

interface WorkerStore
{
    /**
     * @param list<string> $queues
     */
    public function register(WorkerIdentity $identity, array $queues): void;

    public function heartbeat(string $workerId, int $processedCount, WorkerStatus $status): void;

    public function stop(string $workerId): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array;

    /**
     * @param array<string, mixed> $row
     */
    public function isStale(array $row, int $thresholdSeconds): bool;
}
