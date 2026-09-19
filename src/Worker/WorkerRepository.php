<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Worker;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Persistence\Connection;
use Fuzeo\Queue\Persistence\Schema;
use Fuzeo\Queue\Support\Dates;
use Fuzeo\Queue\Support\SystemClock;

final class WorkerRepository implements WorkerStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    /**
     * @param list<string> $queues
     */
    public function register(WorkerIdentity $identity, array $queues): void
    {
        $now = $this->clock->now();
        $this->connection->execute(
            'INSERT INTO ' . $this->table() . ' (
                worker_id, hostname, pid, started_at, last_heartbeat_at, queues, status,
                memory_bytes, processed_count, runtime_version
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $identity->workerId,
                $identity->hostname,
                $identity->pid,
                $this->date($identity->startedAt),
                $this->date($now),
                implode(',', $queues),
                WorkerStatus::Running->value,
                memory_get_usage(true),
                0,
                $identity->runtimeVersion,
            ]
        );
    }

    public function heartbeat(string $workerId, int $processedCount, WorkerStatus $status): void
    {
        $this->connection->execute(
            'UPDATE ' . $this->table() . '
             SET last_heartbeat_at = ?, memory_bytes = ?, processed_count = ?, status = ?
             WHERE worker_id = ?',
            [
                $this->date($this->clock->now()),
                memory_get_usage(true),
                $processedCount,
                $status->value,
                $workerId,
            ]
        );
    }

    public function stop(string $workerId): void
    {
        $this->heartbeat($workerId, $this->processed($workerId), WorkerStatus::Stopped);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->connection->select(
            'SELECT * FROM ' . $this->table() . ' ORDER BY last_heartbeat_at DESC'
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public function isStale(array $row, int $thresholdSeconds): bool
    {
        $heartbeat = $row['last_heartbeat_at'] ?? null;
        if (!is_string($heartbeat) || $heartbeat === '') {
            return true;
        }
        $at = new \DateTimeImmutable($heartbeat, new \DateTimeZone('UTC'));
        $age = $this->clock->now()->getTimestamp() - $at->getTimestamp();

        return $age > $thresholdSeconds && ($row['status'] ?? '') !== WorkerStatus::Stopped->value;
    }

    private function processed(string $workerId): int
    {
        $row = $this->connection->selectOne(
            'SELECT processed_count FROM ' . $this->table() . ' WHERE worker_id = ?',
            [$workerId]
        );

        return (int) ($row['processed_count'] ?? 0);
    }

    private function table(): string
    {
        return Schema::quoteTable($this->connection->prefix(), Schema::WORKERS);
    }

    private function date(\DateTimeImmutable $value): string
    {
        return Dates::utc($value)->format('Y-m-d H:i:s.u');
    }
}
