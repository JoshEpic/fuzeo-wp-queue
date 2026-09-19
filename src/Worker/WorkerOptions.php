<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Worker;

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
    public static function fromCli(array $flags, string $defaultQueue = QueueName::DEFAULT): self
    {
        $queues = $defaultQueue;
        if (isset($flags['queue']) && is_string($flags['queue']) && $flags['queue'] !== '') {
            $queues = $flags['queue'];
        }
        $list = array_values(array_filter(array_map('trim', explode(',', $queues))));

        return new self(
            queues: $list === [] ? [$defaultQueue] : array_map([QueueName::class, 'normalize'], $list),
            sleepSeconds: self::intFlag($flags, 'sleep', 1),
            timeoutSeconds: self::intFlag($flags, 'timeout', 60),
            leaseSeconds: self::intFlag($flags, 'lease', 90),
            memoryBytes: self::memoryFlag($flags['memory'] ?? '128M'),
            maxJobs: self::intFlag($flags, 'max-jobs', 0),
            maxRuntimeSeconds: self::intFlag($flags, 'max-runtime', 0),
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
