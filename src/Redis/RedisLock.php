<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Redis;

use Fuzeo\Queue\Locks\DistributedLock;

final class RedisLock implements DistributedLock
{
    public function __construct(
        private readonly RedisClient $redis,
        private readonly RedisKeys $keys,
        private readonly ScriptCache $scripts,
    ) {
    }

    public function acquire(string $name, string $owner, int $ttlSeconds): bool
    {
        $key = $this->keys->lock($this->normalize($name));
        $ok = $this->redis->command('SET', [$key, $owner, 'NX', 'EX', max(1, $ttlSeconds)]);

        return $ok === true || $ok === 'OK';
    }

    public function release(string $name, string $owner): bool
    {
        $result = $this->scripts->run(
            'lock_release',
            RedisScripts::LOCK_RELEASE,
            [$this->keys->lock($this->normalize($name))],
            [$owner]
        );

        return (int) $result === 1;
    }

    public function extend(string $name, string $owner, int $ttlSeconds): bool
    {
        $result = $this->scripts->run(
            'lock_extend',
            RedisScripts::LOCK_EXTEND,
            [$this->keys->lock($this->normalize($name))],
            [$owner, (string) (max(1, $ttlSeconds) * 1000)]
        );

        return (int) $result === 1;
    }

    private function normalize(string $name): string
    {
        $n = strtolower($name);
        if ($n === '' || strlen($n) > 128) {
            throw new \Fuzeo\Queue\Exceptions\QueueException('Lock name is invalid.');
        }

        return $n;
    }
}
