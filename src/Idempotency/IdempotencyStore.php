<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Idempotency;

interface IdempotencyStore
{
    public function begin(
        IdempotencyIdentity $identity,
        string $ownerToken,
        int $leaseSeconds,
    ): IdempotencyBeginResult;

    /**
     * @param array<string, mixed> $result
     */
    public function complete(string $ownerToken, array $result, int $retainSeconds): void;

    public function fail(string $ownerToken): void;

    public function heartbeat(string $ownerToken, int $leaseSeconds): bool;

    public function lookup(IdempotencyIdentity $identity): ?IdempotencyRecord;

    /**
     * @return list<IdempotencyRecord>
     */
    public function list(int $limit = 50): array;
}
