<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Unique;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Persistence\Connection;
use Fuzeo\Queue\Persistence\DuplicateKey;
use Fuzeo\Queue\Persistence\Schema;
use Fuzeo\Queue\Support\Dates;
use Fuzeo\Queue\Support\SystemClock;

final class MysqlUniqueStore implements UniqueStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function acquire(UniqueIdentity $identity, string $jobId, ?int $ttlSeconds = null): UniqueAcquireResult
    {
        $this->purgeExpired();
        $now = $this->clock->now();
        $expires = $ttlSeconds !== null && $ttlSeconds > 0
            ? $now->add(new \DateInterval('PT' . $ttlSeconds . 'S'))
            : null;
        try {
            $this->connection->execute(
                'INSERT INTO ' . $this->table() . ' (
                    unique_id, job_id, kind, unique_key, origin_package, job_type,
                    network_id, site_id, scope, expires_at, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $identity->hash,
                    $jobId,
                    $identity->kind,
                    $identity->uniqueKey,
                    $identity->originPackage,
                    $identity->jobType,
                    $identity->context->networkId,
                    $identity->context->siteId,
                    $identity->context->scope->value,
                    $expires !== null ? $this->date($expires) : null,
                    $this->date($now),
                ]
            );
        } catch (\Throwable $exception) {
            if (!DuplicateKey::matches($exception)) {
                throw $exception;
            }
            $existing = $this->lookup($identity);
            if ($existing !== null) {
                return new UniqueAcquireResult(false, $existing->jobId);
            }
            throw $exception;
        }

        return new UniqueAcquireResult(true, $jobId);
    }

    public function release(UniqueIdentity $identity, string $jobId, ?int $ttlSeconds = null): void
    {
        if ($ttlSeconds !== null && $ttlSeconds > 0) {
            $expires = $this->clock->now()->add(new \DateInterval('PT' . $ttlSeconds . 'S'));
            $this->connection->execute(
                'UPDATE ' . $this->table() . ' SET `expires_at` = ? WHERE `unique_id` = ? AND `job_id` = ?',
                [$this->date($expires), $identity->hash, $jobId]
            );

            return;
        }
        $this->connection->execute(
            'DELETE FROM ' . $this->table() . ' WHERE `unique_id` = ? AND `job_id` = ?',
            [$identity->hash, $jobId]
        );
    }

    public function lookup(UniqueIdentity $identity): ?UniqueRecord
    {
        $this->purgeExpired();
        $row = $this->connection->selectOne(
            'SELECT * FROM ' . $this->table() . ' WHERE `unique_id` = ?',
            [$identity->hash]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function list(int $limit = 50): array
    {
        $this->purgeExpired();
        $rows = $this->connection->select(
            'SELECT * FROM ' . $this->table() . ' ORDER BY `created_at` DESC LIMIT ' . (int) $limit
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->hydrate($row);
        }

        return $out;
    }

    public function forceRelease(string $hash): void
    {
        $this->connection->execute('DELETE FROM ' . $this->table() . ' WHERE `unique_id` = ?', [$hash]);
    }

    private function purgeExpired(): void
    {
        $this->connection->execute(
            'DELETE FROM ' . $this->table() . ' WHERE `expires_at` IS NOT NULL AND `expires_at` <= ?',
            [$this->date($this->clock->now())]
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): UniqueRecord
    {
        $expires = $row['expires_at'] ?? null;

        return new UniqueRecord(
            (string) $row['unique_id'],
            (string) $row['job_id'],
            (string) $row['unique_key'],
            (string) $row['origin_package'],
            (string) $row['job_type'],
            is_string($expires) && $expires !== '' ? Dates::fromDatabase($expires) : null,
        );
    }

    private function table(): string
    {
        return Schema::quoteTable($this->connection->prefix(), Schema::UNIQUE);
    }

    private function date(\DateTimeImmutable $value): string
    {
        return Dates::toDatabase($value);
    }
}
