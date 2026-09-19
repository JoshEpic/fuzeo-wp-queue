<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

use Fuzeo\Queue\Persistence\Connection;
use Fuzeo\Queue\Persistence\DuplicateKey;
use Fuzeo\Queue\Persistence\Schema;
use Fuzeo\Queue\Support\Dates;

final class MysqlMigrationStore implements MigrationStore
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function insert(MigrationRecord $record): bool
    {
        try {
            $this->connection->execute(
                'INSERT INTO ' . $this->table() . ' (
                    migration_id, descriptor_id, descriptor_version, source_system, source_identifier,
                    source_snapshot, destination_type, destination_id, origin_package, site_id, network_id,
                    status, migrated_at, rolled_back_at, metadata, rollback_available
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                $this->bindings($record)
            );

            return true;
        } catch (\Throwable $exception) {
            if (DuplicateKey::matches($exception)) {
                return false;
            }
            throw $exception;
        }
    }

    public function update(MigrationRecord $record): void
    {
        $this->connection->execute(
            'UPDATE ' . $this->table() . ' SET
                descriptor_id = ?, descriptor_version = ?, source_snapshot = ?, destination_type = ?,
                destination_id = ?, status = ?, rolled_back_at = ?, metadata = ?, rollback_available = ?
             WHERE migration_id = ?',
            [
                $record->descriptorId,
                $record->descriptorVersion,
                json_encode($record->sourceSnapshot, JSON_THROW_ON_ERROR),
                $record->destinationType,
                $record->destinationId,
                $record->status->value,
                $record->rolledBackAt !== null ? Dates::toDatabase($record->rolledBackAt) : null,
                json_encode($record->metadata, JSON_THROW_ON_ERROR),
                $record->rollbackAvailable ? 1 : 0,
                $record->migrationId,
            ]
        );
    }

    public function find(string $migrationId): ?MigrationRecord
    {
        $row = $this->connection->selectOne(
            'SELECT * FROM ' . $this->table() . ' WHERE migration_id = ?',
            [$migrationId]
        );

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function findBySource(SourceSystem $system, string $sourceIdentifier, int $siteId, int $networkId): ?MigrationRecord
    {
        $row = $this->connection->selectOne(
            'SELECT * FROM ' . $this->table() . ' WHERE source_system = ? AND source_identifier = ? AND site_id = ? AND network_id = ?',
            [$system->value, $sourceIdentifier, $siteId, $networkId]
        );

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function list(int $siteId, int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        if ($siteId === 0) {
            $rows = $this->connection->select(
                'SELECT * FROM ' . $this->table() . ' ORDER BY migrated_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset
            );
        } else {
            $rows = $this->connection->select(
                'SELECT * FROM ' . $this->table() . ' WHERE site_id = ? ORDER BY migrated_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
                [$siteId]
            );
        }
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->hydrate($row);
        }

        return $out;
    }

    /**
     * @return list<mixed>
     */
    private function bindings(MigrationRecord $record): array
    {
        return [
            $record->migrationId,
            $record->descriptorId,
            $record->descriptorVersion,
            $record->sourceSystem->value,
            $record->sourceIdentifier,
            json_encode($record->sourceSnapshot, JSON_THROW_ON_ERROR),
            $record->destinationType,
            $record->destinationId,
            $record->originPackage,
            $record->siteId,
            $record->networkId,
            $record->status->value,
            Dates::toDatabase($record->migratedAt),
            $record->rolledBackAt !== null ? Dates::toDatabase($record->rolledBackAt) : null,
            json_encode($record->metadata, JSON_THROW_ON_ERROR),
            $record->rollbackAvailable ? 1 : 0,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): MigrationRecord
    {
        $snapshot = [];
        if (is_string($row['source_snapshot'] ?? null) && $row['source_snapshot'] !== '') {
            $decoded = json_decode((string) $row['source_snapshot'], true);
            $snapshot = is_array($decoded) ? $decoded : [];
        }
        $metadata = [];
        if (is_string($row['metadata'] ?? null) && $row['metadata'] !== '') {
            $decoded = json_decode((string) $row['metadata'], true);
            $metadata = is_array($decoded) ? $decoded : [];
        }
        $rolled = $row['rolled_back_at'] ?? null;

        return new MigrationRecord(
            migrationId: (string) $row['migration_id'],
            descriptorId: (string) $row['descriptor_id'],
            descriptorVersion: (int) $row['descriptor_version'],
            sourceSystem: SourceSystem::from((string) $row['source_system']),
            sourceIdentifier: (string) $row['source_identifier'],
            sourceSnapshot: $snapshot,
            destinationType: (string) $row['destination_type'],
            destinationId: (string) $row['destination_id'],
            originPackage: (string) $row['origin_package'],
            siteId: (int) $row['site_id'],
            networkId: (int) $row['network_id'],
            status: MigrationStatus::from((string) $row['status']),
            migratedAt: Dates::fromDatabase((string) $row['migrated_at']),
            rolledBackAt: is_string($rolled) && $rolled !== '' ? Dates::fromDatabase($rolled) : null,
            metadata: $metadata,
            rollbackAvailable: (int) ($row['rollback_available'] ?? 0) === 1,
        );
    }

    private function table(): string
    {
        return Schema::quoteTable($this->connection->prefix(), Schema::MIGRATIONS);
    }
}
