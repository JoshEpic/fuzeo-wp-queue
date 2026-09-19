<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Redis;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Runtime\PackageInfo;
use Fuzeo\Queue\Support\Dates;
use Fuzeo\Queue\Support\SystemClock;
use Fuzeo\Queue\Worker\WorkerIdentity;
use Fuzeo\Queue\Worker\WorkerStatus;
use Fuzeo\Queue\Worker\WorkerStore;

final class RedisWorkerStore implements WorkerStore
{
    public function __construct(
        private readonly RedisClient $redis,
        private readonly RedisKeys $keys,
        private readonly Clock $clock = new SystemClock(),
        private readonly int $ttlSeconds = 60,
    ) {
    }

    public function register(WorkerIdentity $identity, array $queues): void
    {
        $this->write($identity->workerId, [
            'worker_id' => $identity->workerId,
            'hostname' => $identity->hostname,
            'pid' => (string) $identity->pid,
            'started_at' => Dates::toAtom($identity->startedAt),
            'last_heartbeat_at' => Dates::toAtom($this->clock->now()),
            'queues' => implode(',', $queues),
            'status' => WorkerStatus::Running->value,
            'memory_bytes' => (string) memory_get_usage(true),
            'processed_count' => '0',
            'runtime_version' => $identity->runtimeVersion !== '' ? $identity->runtimeVersion : PackageInfo::VERSION,
            'runtime_generation' => $identity->runtimeGeneration,
            'deployment_generation' => $identity->deploymentGeneration !== '' ? $identity->deploymentGeneration : $identity->runtimeGeneration,
            'schema_version' => (string) $identity->schemaVersion,
            'restart_generation' => $identity->restartGeneration,
        ]);
        $this->redis->command('SADD', [$this->keys->workers(), $identity->workerId]);
    }

    public function heartbeat(
        string $workerId,
        int $processedCount,
        WorkerStatus $status,
        string $recycleReason = '',
        string $generation = '',
    ): void {
        $fields = [
            $this->keys->worker($workerId),
            'last_heartbeat_at',
            Dates::toAtom($this->clock->now()),
            'processed_count',
            (string) $processedCount,
            'status',
            $status->value,
            'memory_bytes',
            (string) memory_get_usage(true),
        ];
        if ($recycleReason !== '') {
            $fields[] = 'recycle_reason';
            $fields[] = $recycleReason;
        }
        if ($generation !== '') {
            $fields[] = 'runtime_generation';
            $fields[] = $generation;
            $fields[] = 'deployment_generation';
            $fields[] = $generation;
        }
        $this->redis->command('HSET', $fields);
        $this->redis->command('EXPIRE', [$this->keys->worker($workerId), $this->ttlSeconds]);
    }

    public function stop(string $workerId): void
    {
        $this->heartbeat($workerId, (int) $this->field($workerId, 'processed_count'), WorkerStatus::Stopped);
    }

    public function all(): array
    {
        $ids = $this->redis->command('SMEMBERS', [$this->keys->workers()]);
        if (!is_array($ids)) {
            return [];
        }
        $out = [];
        foreach ($ids as $id) {
            if (!is_string($id)) {
                continue;
            }
            $row = $this->redis->command('HGETALL', [$this->keys->worker($id)]);
            if (is_array($row) && $row !== []) {
                $out[] = $this->pairs($row);
            }
        }

        return $out;
    }

    public function isStale(array $row, int $thresholdSeconds): bool
    {
        $heartbeat = $row['last_heartbeat_at'] ?? null;
        if (!is_string($heartbeat) || $heartbeat === '') {
            return true;
        }
        $at = Dates::fromAtom($heartbeat);
        $age = $this->clock->now()->getTimestamp() - $at->getTimestamp();

        return $age > $thresholdSeconds && ($row['status'] ?? '') !== WorkerStatus::Stopped->value;
    }

    /**
     * @param array<string, string> $fields
     */
    private function write(string $workerId, array $fields): void
    {
        $args = [$this->keys->worker($workerId)];
        foreach ($fields as $key => $value) {
            $args[] = $key;
            $args[] = $value;
        }
        $this->redis->command('HSET', $args);
        $this->redis->command('EXPIRE', [$this->keys->worker($workerId), $this->ttlSeconds]);
    }

    private function field(string $workerId, string $name): string
    {
        $value = $this->redis->command('HGET', [$this->keys->worker($workerId), $name]);

        return is_string($value) ? $value : '0';
    }

    /**
     * @param array<mixed> $row
     * @return array<string, mixed>
     */
    private function pairs(array $row): array
    {
        if (array_is_list($row)) {
            $out = [];
            for ($i = 0; $i + 1 < count($row); $i += 2) {
                $key = $row[$i];
                $value = $row[$i + 1];
                if (is_string($key)) {
                    $out[$key] = $value;
                }
            }

            return $out;
        }
        $out = [];
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
