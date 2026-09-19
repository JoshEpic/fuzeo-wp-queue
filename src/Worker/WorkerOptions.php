<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Worker;

use Fuzeo\Queue\Config\Config;
use Fuzeo\Queue\Jobs\QueueName;

final class WorkerOptions
{
    /**
     * @param list<string> $queues
     */
    public function __construct(
        public readonly array $queues = [QueueName::DEFAULT],
        public readonly int $sleepSeconds = 1,
        public readonly int $timeoutSeconds = 60,
        public readonly int $leaseSeconds = 90,
        public readonly int $memoryBytes = 134217728,
        public readonly int $maxJobs = 0,
        public readonly int $maxRuntimeSeconds = 0,
        public readonly int $heartbeatIntervalSeconds = 10,
        public readonly int $staleWorkerSeconds = 30,
        public readonly int $generationCheckInterval = 10,
        public readonly int $gcInterval = 50,
        public readonly bool $runtimeReset = true,
        public readonly bool $recycleOnContextError = true,
        public readonly string $processTitle = '',
        public readonly \Fuzeo\Queue\Execution\ProcessType $processType = \Fuzeo\Queue\Execution\ProcessType::Persistent,
        public readonly ?string $executionClass = null,
        public readonly ?int $maxTimeoutSeconds = null,
    ) {
        if ($this->queues === []) {
            throw new \Fuzeo\Queue\Exceptions\QueueException('A worker must listen to at least one queue.');
        }
        foreach ($this->queues as $queue) {
            QueueName::assertValid($queue);
        }
        if ($this->sleepSeconds < 0 || $this->timeoutSeconds < 1 || $this->leaseSeconds < 1) {
            throw new \Fuzeo\Queue\Exceptions\ConfigurationException('Invalid worker timing options.');
        }
        if ($this->memoryBytes < 1) {
            throw new \Fuzeo\Queue\Exceptions\ConfigurationException('memoryBytes must be positive.');
        }
    }

    /**
     * @param array<string, mixed> $flags
     */
    public static function fromCli(array $flags, string $defaultQueue = QueueName::DEFAULT, ?Config $config = null): self
    {
        $queues = $defaultQueue;
        if (isset($flags['queue']) && is_string($flags['queue']) && $flags['queue'] !== '') {
            $queues = $flags['queue'];
        }
        $list = array_values(array_filter(array_map('trim', explode(',', $queues))));
        $defaults = $config ?? new Config();
        $once = isset($flags['once']);
        $maxJobs = self::intFlag($flags, 'max-jobs', $once ? 25 : $defaults->workerMaxJobs);
        $maxRuntime = self::intFlag($flags, 'max-runtime', $once ? 50 : $defaults->workerMaxRuntimeSeconds);
        $sleep = self::intFlag($flags, 'sleep', $once ? 0 : $defaults->workerSleepSeconds);
        $processType = \Fuzeo\Queue\Execution\ProcessType::Persistent;
        if (isset($flags['process-type']) && is_string($flags['process-type'])) {
            $processType = \Fuzeo\Queue\Execution\ProcessType::tryFrom($flags['process-type'])
                ?? \Fuzeo\Queue\Execution\ProcessType::Persistent;
        } elseif ($once || ($sleep === 0 && $maxJobs > 0 && $maxRuntime > 0 && $maxRuntime <= 120)) {
            $processType = \Fuzeo\Queue\Execution\ProcessType::CronCli;
        }

        return new self(
            queues: $list === [] ? [$defaultQueue] : array_map([QueueName::class, 'normalize'], $list),
            sleepSeconds: $sleep,
            timeoutSeconds: self::intFlag($flags, 'timeout', $defaults->workerTimeoutSeconds),
            leaseSeconds: self::intFlag($flags, 'lease', $defaults->leaseSeconds),
            memoryBytes: self::memoryFlag($flags['memory'] ?? $defaults->workerMemoryBytes),
            maxJobs: $maxJobs,
            maxRuntimeSeconds: $maxRuntime,
            heartbeatIntervalSeconds: $defaults->heartbeatIntervalSeconds,
            staleWorkerSeconds: $defaults->staleWorkerSeconds,
            generationCheckInterval: $defaults->generationCheckInterval,
            gcInterval: $defaults->gcInterval,
            runtimeReset: $defaults->runtimeReset,
            recycleOnContextError: $defaults->workerRecycleOnContextError,
            processType: $processType,
        );
    }

    /**
     * @param array<string, mixed> $flags
     */
    private static function intFlag(array $flags, string $key, int $default): int
    {
        if (!isset($flags[$key])) {
            return $default;
        }
        if (is_int($flags[$key])) {
            return $flags[$key];
        }
        if (is_string($flags[$key]) && is_numeric($flags[$key])) {
            return (int) $flags[$key];
        }

        throw new \Fuzeo\Queue\Exceptions\ConfigurationException('CLI option --' . $key . ' must be an integer.');
    }

    private static function memoryFlag(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return 134217728;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        if (preg_match('/^(\d+)\s*([KMG])B?$/i', $value, $matches) !== 1) {
            throw new \Fuzeo\Queue\Exceptions\ConfigurationException('Invalid --memory value.');
        }
        $amount = (int) $matches[1];
        $unit = strtoupper($matches[2]);

        return match ($unit) {
            'K' => $amount * 1024,
            'M' => $amount * 1024 * 1024,
            'G' => $amount * 1024 * 1024 * 1024,
            default => $amount,
        };
    }
}
