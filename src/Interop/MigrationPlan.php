<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

final class MigrationPlan
{
    /**
     * @param list<string> $actions
     * @param array<string, mixed> $payloadSummary
     * @param array<string, mixed> $sourceSnapshot
     */
    public function __construct(
        public readonly string $descriptorId,
        public readonly SourceSystem $sourceSystem,
        public readonly string $sourceLabel,
        public readonly string $sourceIdentifier,
        public readonly ?string $nextRun,
        public readonly string $destinationType,
        public readonly string $destinationId,
        public readonly string $queue,
        public readonly array $payloadSummary,
        public readonly array $actions,
        public readonly bool $rollbackAvailable,
        public readonly Compatibility $compatibility,
        public readonly array $sourceSnapshot,
        public readonly int $siteId,
        public readonly int $networkId,
        public readonly string $originPackage,
        public readonly int $descriptorVersion,
        public readonly string $warning = '',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'descriptor_id' => $this->descriptorId,
            'source_system' => $this->sourceSystem->value,
            'source' => $this->sourceLabel,
            'source_identifier' => $this->sourceIdentifier,
            'next_run' => $this->nextRun,
            'destination_type' => $this->destinationType,
            'destination_id' => $this->destinationId,
            'queue' => $this->queue,
            'payload' => $this->payloadSummary,
            'actions' => $this->actions,
            'rollback_available' => $this->rollbackAvailable,
            'compatibility' => $this->compatibility->value,
            'site_id' => $this->siteId,
            'network_id' => $this->networkId,
            'origin' => $this->originPackage,
            'descriptor_version' => $this->descriptorVersion,
            'warning' => $this->warning,
        ];
    }
}
