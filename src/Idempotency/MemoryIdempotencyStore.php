<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Idempotency;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Exceptions\DriverException;
use Fuzeo\Queue\Support\SystemClock;

final class MemoryIdempotencyStore implements IdempotencyStore
{
    /** @var array<string, IdempotencyRecord> */
    private array $byHash = [];

    /** @var array<string, string> */
    private array $hashByToken = [];

    public function __construct(private readonly Clock $clock = new SystemClock())
    {
    }

    public function begin(IdempotencyIdentity $identity, string $ownerToken, int $leaseSeconds): IdempotencyBeginResult
    {
        $this->expire();
        $existing = $this->byHash[$identity->hash] ?? null;
        if ($existing !== null) {
            return new IdempotencyBeginResult(false, $existing->status, null, $existing->result);
        }
        $now = $this->clock->now();
        $record = new IdempotencyRecord(
            $identity->hash,
            $identity->key,
            IdempotencyStatus::Started,
            $ownerToken,
            $now,
            null,
            $now->add(new \DateInterval('PT' . max(1, $leaseSeconds) . 'S')),
            null,
        );
        $this->byHash[$identity->hash] = $record;
        $this->hashByToken[$ownerToken] = $identity->hash;

        return new IdempotencyBeginResult(true, IdempotencyStatus::Started, $ownerToken, null);
    }

    public function complete(string $ownerToken, array $result, int $retainSeconds): void
    {
        $hash = $this->hashByToken[$ownerToken] ?? null;
        $record = $hash !== null ? ($this->byHash[$hash] ?? null) : null;
        if ($record === null || $record->ownerToken !== $ownerToken || $record->status !== IdempotencyStatus::Started) {
            throw new DriverException('Stale idempotency owner cannot complete this key.');
        }
        $now = $this->clock->now();
        $this->byHash[$hash] = new IdempotencyRecord(
            $record->hash,
            $record->key,
            IdempotencyStatus::Completed,
            $record->ownerToken,
            $record->startedAt,
            $now,
            $now->add(new \DateInterval('PT' . max(1, $retainSeconds) . 'S')),
            $result,
        );
    }

    public function fail(string $ownerToken): void
    {
        $hash = $this->hashByToken[$ownerToken] ?? null;
        $record = $hash !== null ? ($this->byHash[$hash] ?? null) : null;
        if ($record === null || $record->ownerToken !== $ownerToken) {
            throw new DriverException('Stale idempotency owner cannot fail this key.');
        }
        unset($this->byHash[$hash], $this->hashByToken[$ownerToken]);
    }

    public function heartbeat(string $ownerToken, int $leaseSeconds): bool
    {
        $hash = $this->hashByToken[$ownerToken] ?? null;
        $record = $hash !== null ? ($this->byHash[$hash] ?? null) : null;
        if ($record === null || $record->ownerToken !== $ownerToken || $record->status !== IdempotencyStatus::Started) {
            return false;
        }
        $this->byHash[$hash] = new IdempotencyRecord(
            $record->hash,
            $record->key,
            $record->status,
            $record->ownerToken,
            $record->startedAt,
            $record->completedAt,
            $this->clock->now()->add(new \DateInterval('PT' . max(1, $leaseSeconds) . 'S')),
            $record->result,
        );

        return true;
    }

    public function lookup(IdempotencyIdentity $identity): ?IdempotencyRecord
    {
        $this->expire();

        return $this->byHash[$identity->hash] ?? null;
    }

    public function list(int $limit = 50): array
    {
        $this->expire();

        return array_slice(array_values($this->byHash), 0, $limit);
    }

    private function expire(): void
    {
        $now = $this->clock->now();
        foreach ($this->byHash as $hash => $record) {
            if ($record->expiresAt <= $now) {
                unset($this->hashByToken[$record->ownerToken], $this->byHash[$hash]);
            }
        }
    }
}
