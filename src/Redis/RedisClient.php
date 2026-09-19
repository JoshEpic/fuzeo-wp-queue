<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Redis;

interface RedisClient
{
    /**
     * @param list<string> $keys
     * @param list<mixed> $argv
     */
    public function eval(string $script, array $keys, array $argv): mixed;

    /**
     * @param list<string> $keys
     * @param list<mixed> $argv
     */
    public function evalSha(string $sha, array $keys, array $argv): mixed;

    public function scriptLoad(string $script): string;

    /**
     * @param list<mixed> $args
     */
    public function command(string $name, array $args = []): mixed;

    public function ping(): bool;

    public function reconnect(): void;

    /**
     * @return array<string, string>
     */
    public function serverInfo(): array;

    public function configGet(string $parameter): string;
}
