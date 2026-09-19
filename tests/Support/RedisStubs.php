<?php

declare(strict_types=1);

if (class_exists(\Redis::class, false)) {
    return;
}

class Redis
{
    public const OPT_READ_TIMEOUT = 3;

    public function connect(string $host, int $port = 6379, float $timeout = 0.0): bool
    {
        unset($host, $port, $timeout);

        return false;
    }

    public function pconnect(string $host, int $port = 6379, float $timeout = 0.0, string $persistentId = ''): bool
    {
        unset($host, $port, $timeout, $persistentId);

        return false;
    }

    public function close(): bool
    {
        return true;
    }

    public function auth(mixed $credentials): bool
    {
        unset($credentials);

        return false;
    }

    public function select(int $database): bool
    {
        unset($database);

        return true;
    }

    public function setOption(int $option, mixed $value): bool
    {
        unset($option, $value);

        return true;
    }

    public function ping(): mixed
    {
        return false;
    }

    public function info(): mixed
    {
        return [];
    }

    public function config(string $operation, string $parameter): mixed
    {
        unset($operation, $parameter);

        return [];
    }

    public function script(string $command, mixed ...$args): mixed
    {
        unset($command, $args);

        return '';
    }

    /**
     * @param array<int, mixed> $args
     */
    public function evalSha(string $sha, array $args, int $numKeys): mixed
    {
        unset($sha, $args, $numKeys);

        return false;
    }

    /**
     * @param array<int, mixed> $args
     */
    public function eval(string $script, array $args, int $numKeys): mixed
    {
        unset($script, $args, $numKeys);

        return false;
    }

    public function rawCommand(string $command, mixed ...$args): mixed
    {
        unset($command, $args);

        return false;
    }
}

class RedisException extends Exception
{
}
