<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Config;

final class Config
{
    public const DRIVER_UNAVAILABLE = 'unavailable';
    public const DRIVER_MEMORY = 'memory';
    public const DRIVER_MYSQL = 'mysql';
    public const DRIVER_REDIS = 'redis';

    public function __construct(
        public readonly string $driver = self::DRIVER_UNAVAILABLE,
        public readonly string $defaultQueue = 'default',
        public readonly int $maxPayloadBytes = 262144,
        public readonly int $maxPayloadDepth = 32,
        public readonly int $defaultMaxAttempts = 3,
        public readonly int $defaultTimeoutSeconds = 60,
        public readonly bool $redactPayloads = true,
        public readonly int $leaseSeconds = 90,
        public readonly int $workerSleepSeconds = 1,
        public readonly int $workerTimeoutSeconds = 60,
        public readonly int $workerMemoryBytes = 134217728,
        public readonly int $workerMaxJobs = 0,
        public readonly int $workerMaxRuntimeSeconds = 0,
        public readonly int $heartbeatIntervalSeconds = 10,
        public readonly int $staleWorkerSeconds = 30,
        public readonly int $completedRetentionDays = 7,
        public readonly int $deadRetentionDays = 30,
        public readonly int $defaultJitterPercent = 0,
        public readonly string $redisDsn = '',
        public readonly string $redisNamespace = 'local',
        /** @var array<string, int> */
        public readonly array $concurrency = [],
        /** @var list<array<string, mixed>> */
        public readonly array $rateLimits = [],
        public readonly int $scheduleClaimLeaseSeconds = 30,
        public readonly int $scheduleMaxCatchUp = 100,
        public readonly int $scheduleCatchUpCutoffDays = 7,
        public readonly int $idempotencyLeaseSeconds = 60,
        public readonly int $idempotencyRetainSeconds = 604800,
    ) {
    }

    /**
     * @param array<string, mixed> $values
     */
    public function merge(array $values): self
    {
        return new self(
            driver: $this->string($values, 'driver', $this->driver),
            defaultQueue: $this->string($values, 'default_queue', $this->defaultQueue),
            maxPayloadBytes: $this->int($values, 'max_payload_bytes', $this->maxPayloadBytes),
            maxPayloadDepth: $this->int($values, 'max_payload_depth', $this->maxPayloadDepth),
            defaultMaxAttempts: $this->int($values, 'default_max_attempts', $this->defaultMaxAttempts),
            defaultTimeoutSeconds: $this->int($values, 'default_timeout_seconds', $this->defaultTimeoutSeconds),
            redactPayloads: $this->bool($values, 'redact_payloads', $this->redactPayloads),
            leaseSeconds: $this->int($values, 'lease_seconds', $this->leaseSeconds),
            workerSleepSeconds: $this->int($values, 'worker_sleep', $this->workerSleepSeconds),
            workerTimeoutSeconds: $this->int($values, 'worker_timeout', $this->workerTimeoutSeconds),
            workerMemoryBytes: $this->int($values, 'worker_memory', $this->workerMemoryBytes),
            workerMaxJobs: $this->int($values, 'worker_max_jobs', $this->workerMaxJobs),
            workerMaxRuntimeSeconds: $this->int($values, 'worker_max_runtime', $this->workerMaxRuntimeSeconds),
            heartbeatIntervalSeconds: $this->int($values, 'heartbeat_interval', $this->heartbeatIntervalSeconds),
            staleWorkerSeconds: $this->int($values, 'stale_worker_threshold', $this->staleWorkerSeconds),
            completedRetentionDays: $this->int($values, 'completed_retention_days', $this->completedRetentionDays),
            deadRetentionDays: $this->int($values, 'dead_retention_days', $this->deadRetentionDays),
            defaultJitterPercent: $this->int($values, 'default_jitter_percent', $this->defaultJitterPercent),
            redisDsn: $this->stringAllowEmpty($values, 'redis_dsn', $this->redisDsn),
            redisNamespace: $this->string($values, 'redis_namespace', $this->redisNamespace),
            concurrency: $this->intMap($values, 'concurrency', $this->concurrency),
            rateLimits: $this->listOfMaps($values, 'rate_limits', $this->rateLimits),
            scheduleClaimLeaseSeconds: $this->int($values, 'schedule_claim_lease_seconds', $this->scheduleClaimLeaseSeconds),
            scheduleMaxCatchUp: $this->int($values, 'schedule_max_catch_up', $this->scheduleMaxCatchUp),
            scheduleCatchUpCutoffDays: $this->int($values, 'schedule_catch_up_cutoff_days', $this->scheduleCatchUpCutoffDays),
            idempotencyLeaseSeconds: $this->int($values, 'idempotency_lease_seconds', $this->idempotencyLeaseSeconds),
            idempotencyRetainSeconds: $this->int($values, 'idempotency_retain_seconds', $this->idempotencyRetainSeconds),
        );
    }

    /**
     * @param array<string, mixed> $values
     */
    private function string(array $values, string $key, string $default): string
    {
        if (!array_key_exists($key, $values) || $values[$key] === null) {
            return $default;
        }
        if (!is_string($values[$key]) || $values[$key] === '') {
            throw new \Fuzeo\Queue\Exceptions\ConfigurationException('Config key ' . $key . ' must be a non-empty string.');
        }

        return $values[$key];
    }

    /**
     * @param array<string, mixed> $values
     */
    private function int(array $values, string $key, int $default): int
    {
        if (!array_key_exists($key, $values) || $values[$key] === null) {
            return $default;
        }
        if (is_string($values[$key]) && is_numeric($values[$key])) {
            return (int) $values[$key];
        }
        if (!is_int($values[$key])) {
            throw new \Fuzeo\Queue\Exceptions\ConfigurationException('Config key ' . $key . ' must be an integer.');
        }

        return $values[$key];
    }

    /**
     * @param array<string, mixed> $values
     */
    private function bool(array $values, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $values) || $values[$key] === null) {
            return $default;
        }
        $value = $values[$key];
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 'true' || $value === '1' || $value === 1) {
            return true;
        }
        if ($value === 'false' || $value === '0' || $value === 0) {
            return false;
        }

        throw new \Fuzeo\Queue\Exceptions\ConfigurationException('Config key ' . $key . ' must be a boolean.');
    }

    /**
     * @param array<string, mixed> $values
     */
    private function stringAllowEmpty(array $values, string $key, string $default): string
    {
        if (!array_key_exists($key, $values) || $values[$key] === null) {
            return $default;
        }
        if (!is_string($values[$key])) {
            throw new \Fuzeo\Queue\Exceptions\ConfigurationException('Config key ' . $key . ' must be a string.');
        }

        return $values[$key];
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, int> $default
     * @return array<string, int>
     */
    private function intMap(array $values, string $key, array $default): array
    {
        if (!array_key_exists($key, $values) || $values[$key] === null) {
            return $default;
        }
        $raw = $values[$key];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : $raw;
        }
        if (!is_array($raw)) {
            throw new \Fuzeo\Queue\Exceptions\ConfigurationException('Config key ' . $key . ' must be a map of queue => limit.');
        }
        $out = [];
        foreach ($raw as $queue => $max) {
            if (!is_string($queue) || !is_numeric($max)) {
                throw new \Fuzeo\Queue\Exceptions\ConfigurationException('Concurrency map values must be integers.');
            }
            $out[$queue] = (int) $max;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $values
     * @param list<array<string, mixed>> $default
     * @return list<array<string, mixed>>
     */
    private function listOfMaps(array $values, string $key, array $default): array
    {
        if (!array_key_exists($key, $values) || $values[$key] === null) {
            return $default;
        }
        $raw = $values[$key];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : $raw;
        }
        if (!is_array($raw)) {
            throw new \Fuzeo\Queue\Exceptions\ConfigurationException('Config key ' . $key . ' must be a list.');
        }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                throw new \Fuzeo\Queue\Exceptions\ConfigurationException('Each rate limit must be a map.');
            }
            /** @var array<string, mixed> $row */
            $out[] = $row;
        }

        return $out;
    }
}
