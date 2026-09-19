<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

final class MemoryMigrationStore implements MigrationStore
{
    /** @var array<string, MigrationRecord> */
    private array $byId = [];

    /** @var array<string, string> */
    private array $bySource = [];

    public function insert(MigrationRecord $record): bool
    {
        $key = $this->sourceKey($record->sourceSystem, $record->sourceIdentifier, $record->siteId, $record->networkId);
        if (isset($this->bySource[$key])) {
            return false;
        }
        $this->byId[$record->migrationId] = $record;
        $this->bySource[$key] = $record->migrationId;

        return true;
    }

    public function update(MigrationRecord $record): void
    {
        $this->byId[$record->migrationId] = $record;
        $key = $this->sourceKey($record->sourceSystem, $record->sourceIdentifier, $record->siteId, $record->networkId);
        $this->bySource[$key] = $record->migrationId;
    }

    public function find(string $migrationId): ?MigrationRecord
    {
        return $this->byId[$migrationId] ?? null;
    }

    public function findBySource(SourceSystem $system, string $sourceIdentifier, int $siteId, int $networkId): ?MigrationRecord
    {
        $id = $this->bySource[$this->sourceKey($system, $sourceIdentifier, $siteId, $networkId)] ?? null;

        return $id !== null ? ($this->byId[$id] ?? null) : null;
    }

    public function list(int $siteId, int $limit = 50, int $offset = 0): array
    {
        $rows = array_values(array_filter(
            $this->byId,
            static fn (MigrationRecord $row): bool => $siteId === 0 || $row->siteId === $siteId
        ));

        return array_slice($rows, max(0, $offset), max(1, min(200, $limit)));
    }

    private function sourceKey(SourceSystem $system, string $sourceIdentifier, int $siteId, int $networkId): string
    {
        return $system->value . '|' . $sourceIdentifier . '|' . $siteId . '|' . $networkId;
    }
}
