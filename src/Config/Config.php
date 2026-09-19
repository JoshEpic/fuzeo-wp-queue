<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Config;

final class Config
{
    public const DRIVER_UNAVAILABLE = 'unavailable';
    public const DRIVER_MEMORY = 'memory';

    public function __construct(
        public readonly string $driver = self::DRIVER_UNAVAILABLE,
        public readonly string $defaultQueue = 'default',
        public readonly int $maxPayloadBytes = 262144,
        public readonly int $maxPayloadDepth = 32,
        public readonly int $defaultMaxAttempts = 3,
        public readonly int $defaultTimeoutSeconds = 60,
        public readonly bool $redactPayloads = true,
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
}
