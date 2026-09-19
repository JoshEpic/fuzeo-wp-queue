<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

interface MigrationStore
{
    public function insert(MigrationRecord $record): bool;

    public function update(MigrationRecord $record): void;

    public function find(string $migrationId): ?MigrationRecord;

    public function findBySource(SourceSystem $system, string $sourceIdentifier, int $siteId, int $networkId): ?MigrationRecord;

    /**
     * @return list<MigrationRecord>
     */
    public function list(int $siteId, int $limit = 50, int $offset = 0): array;
}
