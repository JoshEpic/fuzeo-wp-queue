<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Unique;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Support\SystemClock;

final class MemoryUniqueStore implements UniqueStore
{
    /** @var array<string, UniqueRecord> */
    private array $rows = [];

    public function __construct(private readonly Clock $clock = new SystemClock())
    {
    }

    public function acquire(UniqueIdentity $identity, string $jobId, ?int $ttlSeconds = null): UniqueAcquireResult
    {
        $this->expire();
        $existing = $this->rows[$identity->hash] ?? null;
        if ($existing !== null) {
            return new UniqueAcquireResult(false, $existing->jobId);
        }
        $expires = $ttlSeconds !== null ? $this->clock->now()->add(new \DateInterval('PT' . $ttlSeconds . 'S')) : null;
        $this->rows[$identity->hash] = new UniqueRecord(
            $identity->hash,
            $jobId,
            $identity->uniqueKey,
            $identity->originPackage,
            $identity->jobType,
            $expires,
        );

        return new UniqueAcquireResult(true, $jobId);
    }

    public function release(UniqueIdentity $identity, string $jobId, ?int $ttlSeconds = null): void
    {
        $current = $this->rows[$identity->hash] ?? null;
        if ($current === null || $current->jobId !== $jobId) {
            return;
        }
        if ($ttlSeconds !== null && $ttlSeconds > 0) {
            $this->rows[$identity->hash] = new UniqueRecord(
                $current->hash,
                $current->jobId,
                $current->uniqueKey,
                $current->originPackage,
                $current->jobType,
                $this->clock->now()->add(new \DateInterval('PT' . $ttlSeconds . 'S')),
            );

            return;
        }
        unset($this->rows[$identity->hash]);
    }

    public function lookup(UniqueIdentity $identity): ?UniqueRecord
    {
        $this->expire();

        return $this->rows[$identity->hash] ?? null;
    }

    public function list(int $limit = 50): array
    {
        $this->expire();

        return array_slice(array_values($this->rows), 0, $limit);
    }

    public function forceRelease(string $hash): void
    {
        unset($this->rows[$hash]);
    }

    private function expire(): void
    {
        $now = $this->clock->now();
        foreach ($this->rows as $hash => $row) {
            if ($row->expiresAt !== null && $row->expiresAt <= $now) {
                unset($this->rows[$hash]);
            }
        }
    }
}
