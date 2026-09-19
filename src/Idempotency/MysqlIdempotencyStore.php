<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Idempotency;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Exceptions\DriverException;
use Fuzeo\Queue\Persistence\Connection;
use Fuzeo\Queue\Persistence\DuplicateKey;
use Fuzeo\Queue\Persistence\Schema;
use Fuzeo\Queue\Support\Dates;
use Fuzeo\Queue\Support\SystemClock;

final class MysqlIdempotencyStore implements IdempotencyStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function begin(IdempotencyIdentity $identity, string $ownerToken, int $leaseSeconds): IdempotencyBeginResult
    {
        $this->purgeExpired();
        $existing = $this->lookup($identity);
        if ($existing !== null) {
            return new IdempotencyBeginResult(false, $existing->status, null, $existing->result);
        }
        $now = $this->clock->now();
        $expires = $now->add(new \DateInterval('PT' . max(1, $leaseSeconds) . 'S'));
        try {
            $this->connection->execute(
                'INSERT INTO ' . $this->table() . ' (
                    idempotency_id, idempotency_key, status, owner_token, result_json,
                    network_id, site_id, scope, started_at, completed_at, expires_at
                ) VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, NULL, ?)',
                [
                    $identity->hash,
                    $identity->key,
                    IdempotencyStatus::Started->value,
                    $ownerToken,
                    $identity->context->networkId,
                    $identity->context->siteId,
                    $identity->context->scope->value,
                    $this->date($now),
                    $this->date($expires),
                ]
            );
        } catch (\Throwable $exception) {
            if (!DuplicateKey::matches($exception)) {
                throw $exception;
            }
            $again = $this->lookup($identity);
            if ($again !== null) {
                return new IdempotencyBeginResult(false, $again->status, null, $again->result);
            }
            throw $exception;
        }

        return new IdempotencyBeginResult(true, IdempotencyStatus::Started, $ownerToken, null);
    }

    public function complete(string $ownerToken, array $result, int $retainSeconds): void
    {
        $now = $this->clock->now();
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        $affected = $this->connection->execute(
            'UPDATE ' . $this->table() . '
             SET `status` = ?, `result_json` = ?, `completed_at` = ?, `expires_at` = ?
             WHERE `owner_token` = ? AND `status` = ?',
            [
                IdempotencyStatus::Completed->value,
                $json,
                $this->date($now),
                $this->date($now->add(new \DateInterval('PT' . max(1, $retainSeconds) . 'S'))),
                $ownerToken,
                IdempotencyStatus::Started->value,
            ]
        );
        if ($affected !== 1) {
            throw new DriverException('Stale idempotency owner cannot complete this key.');
        }
    }

    public function fail(string $ownerToken): void
    {
        $affected = $this->connection->execute(
            'DELETE FROM ' . $this->table() . ' WHERE `owner_token` = ? AND `status` = ?',
            [$ownerToken, IdempotencyStatus::Started->value]
        );
        if ($affected !== 1) {
            throw new DriverException('Stale idempotency owner cannot fail this key.');
        }
    }

    public function heartbeat(string $ownerToken, int $leaseSeconds): bool
    {
        $expires = $this->clock->now()->add(new \DateInterval('PT' . max(1, $leaseSeconds) . 'S'));
        $affected = $this->connection->execute(
            'UPDATE ' . $this->table() . ' SET `expires_at` = ? WHERE `owner_token` = ? AND `status` = ?',
            [$this->date($expires), $ownerToken, IdempotencyStatus::Started->value]
        );

        return $affected === 1;
    }

    public function lookup(IdempotencyIdentity $identity): ?IdempotencyRecord
    {
        $this->purgeExpired();
        $row = $this->connection->selectOne(
            'SELECT * FROM ' . $this->table() . ' WHERE `idempotency_id` = ?',
            [$identity->hash]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function list(int $limit = 50): array
    {
        $this->purgeExpired();
        $rows = $this->connection->select(
            'SELECT * FROM ' . $this->table() . ' ORDER BY `started_at` DESC LIMIT ' . (int) $limit
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->hydrate($row);
        }

        return $out;
    }

    private function purgeExpired(): void
    {
        $this->connection->execute(
            'DELETE FROM ' . $this->table() . ' WHERE `expires_at` <= ?',
            [$this->date($this->clock->now())]
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): IdempotencyRecord
    {
        $result = null;
        if (isset($row['result_json']) && is_string($row['result_json']) && $row['result_json'] !== '') {
            $decoded = json_decode($row['result_json'], true);
            $result = is_array($decoded) ? $decoded : null;
        }
        $completed = $row['completed_at'] ?? null;

        return new IdempotencyRecord(
            (string) $row['idempotency_id'],
            (string) $row['idempotency_key'],
            IdempotencyStatus::from((string) $row['status']),
            (string) $row['owner_token'],
            Dates::fromDatabase((string) $row['started_at']),
            is_string($completed) && $completed !== '' ? Dates::fromDatabase($completed) : null,
            Dates::fromDatabase((string) $row['expires_at']),
            $result,
        );
    }

    private function table(): string
    {
        return Schema::quoteTable($this->connection->prefix(), Schema::IDEMPOTENCY);
    }

    private function date(\DateTimeImmutable $value): string
    {
        return Dates::toDatabase($value);
    }
}
