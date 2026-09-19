<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Unique;

use Fuzeo\Queue\Redis\RedisClient;
use Fuzeo\Queue\Redis\RedisKeys;
use Fuzeo\Queue\Redis\RedisScripts;
use Fuzeo\Queue\Redis\ScriptCache;

final class RedisUniqueStore implements UniqueStore
{
    public function __construct(
        private readonly RedisClient $redis,
        private readonly RedisKeys $keys,
        private readonly ScriptCache $scripts,
    ) {
    }

    public function acquire(UniqueIdentity $identity, string $jobId, ?int $ttlSeconds = null): UniqueAcquireResult
    {
        $key = $this->keys->unique($identity->hash);
        $args = [$key, $jobId, 'NX'];
        if ($ttlSeconds !== null && $ttlSeconds > 0) {
            $args[] = 'EX';
            $args[] = (string) $ttlSeconds;
        }
        $ok = $this->redis->command('SET', $args);
        if ($ok === true || $ok === 'OK') {
            return new UniqueAcquireResult(true, $jobId);
        }
        $existing = $this->redis->command('GET', [$key]);

        return new UniqueAcquireResult(false, is_string($existing) && $existing !== '' ? $existing : $jobId);
    }

    public function release(UniqueIdentity $identity, string $jobId, ?int $ttlSeconds = null): void
    {
        $this->scripts->run(
            'unique_release',
            RedisScripts::UNIQUE_RELEASE,
            [$this->keys->unique($identity->hash)],
            [$jobId, (string) ($ttlSeconds ?? 0)]
        );
    }

    public function lookup(UniqueIdentity $identity): ?UniqueRecord
    {
        $jobId = $this->redis->command('GET', [$this->keys->unique($identity->hash)]);
        if (!is_string($jobId) || $jobId === '') {
            return null;
        }

        return new UniqueRecord(
            $identity->hash,
            $jobId,
            $identity->uniqueKey,
            $identity->originPackage,
            $identity->jobType,
            null,
        );
    }

    public function list(int $limit = 50): array
    {
        unset($limit);

        return [];
    }

    public function forceRelease(string $hash): void
    {
        $this->redis->command('DEL', [$this->keys->unique($hash)]);
    }
}
