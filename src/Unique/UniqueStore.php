<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Unique;

interface UniqueStore
{
    public function acquire(UniqueIdentity $identity, string $jobId, ?int $ttlSeconds = null): UniqueAcquireResult;

    /**
     * Release immediately, or convert to a TTL hold after completion.
     */
    public function release(UniqueIdentity $identity, string $jobId, ?int $ttlSeconds = null): void;

    public function lookup(UniqueIdentity $identity): ?UniqueRecord;

    /**
     * @return list<UniqueRecord>
     */
    public function list(int $limit = 50): array;

    public function forceRelease(string $hash): void;
}
