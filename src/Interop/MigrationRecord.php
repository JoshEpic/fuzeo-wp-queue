<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

final class MigrationRecord
{
    /**
     * @param array<string, mixed> $sourceSnapshot
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $migrationId,
        public readonly string $descriptorId,
        public readonly int $descriptorVersion,
        public readonly SourceSystem $sourceSystem,
        public readonly string $sourceIdentifier,
        public readonly array $sourceSnapshot,
        public readonly string $destinationType,
        public readonly string $destinationId,
        public readonly string $originPackage,
        public readonly int $siteId,
        public readonly int $networkId,
        public readonly MigrationStatus $status,
        public readonly \DateTimeImmutable $migratedAt,
        public readonly ?\DateTimeImmutable $rolledBackAt,
        public readonly array $metadata,
        public readonly bool $rollbackAvailable,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'migration_id' => $this->migrationId,
            'descriptor_id' => $this->descriptorId,
            'descriptor_version' => $this->descriptorVersion,
            'source_system' => $this->sourceSystem->value,
            'source_identifier' => $this->sourceIdentifier,
            'source_snapshot' => $this->sourceSnapshot,
            'destination_type' => $this->destinationType,
            'destination_id' => $this->destinationId,
            'origin' => $this->originPackage,
            'site_id' => $this->siteId,
            'network_id' => $this->networkId,
            'status' => $this->status->value,
            'migrated_at' => $this->migratedAt->format(\DateTimeInterface::ATOM),
            'rolled_back_at' => $this->rolledBackAt?->format(\DateTimeInterface::ATOM),
            'metadata' => $this->metadata,
            'rollback_available' => $this->rollbackAvailable,
        ];
    }

    public function withStatus(MigrationStatus $status, ?\DateTimeImmutable $rolledBackAt = null, bool $rollbackAvailable = false): self
    {
        return new self(
            $this->migrationId,
            $this->descriptorId,
            $this->descriptorVersion,
            $this->sourceSystem,
            $this->sourceIdentifier,
            $this->sourceSnapshot,
            $this->destinationType,
            $this->destinationId,
            $this->originPackage,
            $this->siteId,
            $this->networkId,
            $status,
            $this->migratedAt,
            $rolledBackAt ?? $this->rolledBackAt,
            $this->metadata,
            $rollbackAvailable,
        );
    }
}
