<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Redis;

use Fuzeo\Queue\Exceptions\ConfigurationException;

final class RedisSettings
{
    public function __construct(
        public readonly string $host = '127.0.0.1',
        public readonly int $port = 6379,
        public readonly int $database = 0,
        public readonly string $username = '',
        public readonly string $password = '',
        public readonly bool $tls = false,
        public readonly float $timeoutSeconds = 2.0,
        public readonly float $readTimeoutSeconds = 2.0,
        public readonly bool $persistent = false,
        public readonly string $persistentId = 'fuzeo-queue',
        public readonly string $namespace = 'local',
    ) {
        if ($this->host === '') {
            throw new ConfigurationException('Redis host is required.');
        }
        if ($this->port < 1 || $this->port > 65535) {
            throw new ConfigurationException('Redis port is invalid.');
        }
        if ($this->database < 0) {
            throw new ConfigurationException('Redis database must be >= 0.');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $this->namespace)) {
            throw new ConfigurationException(
                'Redis namespace must be 1-64 letters, numbers, underscores, or hyphens.'
            );
        }
    }

    public static function fromDsn(string $dsn, string $namespace = 'local'): self
    {
        $parts = parse_url($dsn);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new ConfigurationException('Invalid Redis DSN.');
        }
        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'redis' && $scheme !== 'rediss') {
            throw new ConfigurationException('Redis DSN must use redis:// or rediss://.');
        }
        $path = isset($parts['path']) ? ltrim((string) $parts['path'], '/') : '0';
        $database = is_numeric($path) ? (int) $path : 0;

        return new self(
            host: (string) $parts['host'],
            port: isset($parts['port']) ? (int) $parts['port'] : 6379,
            database: $database,
            username: isset($parts['user']) ? rawurldecode((string) $parts['user']) : '',
            password: isset($parts['pass']) ? rawurldecode((string) $parts['pass']) : '',
            tls: $scheme === 'rediss',
            namespace: $namespace,
        );
    }

    public function prefix(): string
    {
        return 'fuzeo_queue:' . $this->namespace;
    }

    public function redactedEndpoint(): string
    {
        $scheme = $this->tls ? 'rediss' : 'redis';

        return $scheme . '://' . $this->host . ':' . $this->port . '/' . $this->database;
    }
}
