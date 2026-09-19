<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Redis;

use Fuzeo\Queue\Exceptions\DriverException;

/**
 * PhpRedis (ext-redis) connection. Primary production Redis client.
 */
final class PhpRedisConnection implements RedisClient
{
    private \Redis $redis;

    public function __construct(
        private readonly RedisSettings $settings,
        ?\Redis $redis = null,
    ) {
        if (!class_exists(\Redis::class)) {
            throw new DriverException(
                'The redis driver requires the PhpRedis extension (ext-redis). Install php-redis or choose the mysql driver.'
            );
        }
        $this->redis = $redis ?? new \Redis();
        if ($redis === null) {
            $this->connect();
        }
    }

    /**
     * @param list<string> $keys
     * @param list<mixed> $argv
     */
    public function eval(string $script, array $keys, array $argv): mixed
    {
        return $this->runEval(null, $script, $keys, $argv);
    }

    /**
     * @param list<string> $keys
     * @param list<mixed> $argv
     */
    public function evalSha(string $sha, array $keys, array $argv): mixed
    {
        return $this->runEval($sha, null, $keys, $argv);
    }

    public function scriptLoad(string $script): string
    {
        $sha = $this->redis->script('load', $script);
        if (!is_string($sha) || $sha === '') {
            throw new DriverException('Unable to load Redis Lua script.');
        }

        return $sha;
    }

    public function command(string $name, array $args = []): mixed
    {
        try {
            return $this->redis->rawCommand($name, ...$args);
        } catch (\RedisException $exception) {
            throw new DriverException('Redis command failed: ' . $exception->getMessage(), 0, $exception);
        }
    }

    public function ping(): bool
    {
        try {
            $pong = $this->redis->ping();

            return $pong === true || $pong === '+PONG' || $pong === 'PONG';
        } catch (\RedisException) {
            return false;
        }
    }

    public function reconnect(): void
    {
        try {
            $this->redis->close();
        } catch (\RedisException) {
        }
        $this->connect();
    }

    public function serverInfo(): array
    {
        try {
            $info = $this->redis->info();
        } catch (\RedisException $exception) {
            throw new DriverException('Redis INFO failed: ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($info)) {
            return [];
        }
        $out = [];
        foreach ($info as $key => $value) {
            if (is_string($key)) {
                $out[$key] = is_scalar($value) ? (string) $value : '';
            }
        }

        return $out;
    }

    public function configGet(string $parameter): string
    {
        try {
            $value = $this->redis->config('GET', $parameter);
        } catch (\RedisException) {
            return '';
        }
        if (is_array($value)) {
            $first = $value[$parameter] ?? $value[1] ?? '';

            return is_scalar($first) ? (string) $first : '';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    private function connect(): void
    {
        $timeout = max(0.1, $this->settings->timeoutSeconds);
        try {
            $host = $this->settings->tls ? 'tls://' . $this->settings->host : $this->settings->host;
            if ($this->settings->persistent) {
                $ok = $this->redis->pconnect(
                    $host,
                    $this->settings->port,
                    $timeout,
                    $this->settings->persistentId
                );
            } else {
                $ok = $this->redis->connect($host, $this->settings->port, $timeout);
            }
            if ($ok !== true) {
                throw new DriverException('Unable to connect to Redis at ' . $this->settings->redactedEndpoint() . '.');
            }
            if ($this->settings->password !== '') {
                $auth = $this->settings->username !== ''
                    ? $this->redis->auth([$this->settings->username, $this->settings->password])
                    : $this->redis->auth($this->settings->password);
                if ($auth !== true) {
                    throw new DriverException('Redis authentication failed.');
                }
            }
            if ($this->settings->database > 0) {
                $this->redis->select($this->settings->database);
            }
            $this->redis->setOption(\Redis::OPT_READ_TIMEOUT, $this->settings->readTimeoutSeconds);
        } catch (\RedisException $exception) {
            throw new DriverException(
                'Redis connection failed at ' . $this->settings->redactedEndpoint() . ': ' . $exception->getMessage(),
                0,
                $exception
            );
        }
    }

    /**
     * @param list<string> $keys
     * @param list<mixed> $argv
     */
    private function runEval(?string $sha, ?string $script, array $keys, array $argv): mixed
    {
        $args = array_merge($keys, $argv);
        $numKeys = count($keys);
        try {
            if ($sha !== null) {
                $value = $this->redis->evalSha($sha, $args, $numKeys);
                if ($value === false) {
                    throw new DriverException('Redis script failed: NOSCRIPT');
                }

                return $value;
            }
            if ($script === null) {
                throw new DriverException('Redis eval requires a script.');
            }

            return $this->redis->eval($script, $args, $numKeys);
        } catch (\RedisException $exception) {
            throw new DriverException('Redis script failed: ' . $exception->getMessage(), 0, $exception);
        }
    }
}
