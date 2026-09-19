<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Orchestration;

use Fuzeo\Queue\Jobs\ExecutionContext;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Support\Dates;

final class ChainRecord
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $chainId,
        public readonly Origin $origin,
        public readonly ExecutionContext $context,
        public readonly ChainState $state,
        public readonly int $currentStep,
        public readonly int $totalSteps,
        public readonly ChainFailurePolicy $failurePolicy,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $startedAt = null,
        public readonly ?\DateTimeImmutable $completedAt = null,
        public readonly ?\DateTimeImmutable $cancelledAt = null,
        public readonly ?\DateTimeImmutable $failedAt = null,
        public readonly bool $cancelRequested = false,
        public readonly ?int $failedStep = null,
        public readonly ?string $failedJobId = null,
        public readonly ?string $failureReason = null,
        public readonly array $metadata = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'chain_id' => $this->chainId,
            'origin' => json_encode($this->origin->toArray(), JSON_THROW_ON_ERROR),
            'network_id' => $this->context->networkId,
            'site_id' => $this->context->siteId,
            'scope' => $this->context->scope->value,
            'state' => $this->state->value,
            'current_step' => $this->currentStep,
            'total_steps' => $this->totalSteps,
            'failure_policy' => $this->failurePolicy->value,
            'created_at' => Dates::toAtom($this->createdAt),
            'started_at' => $this->startedAt !== null ? Dates::toAtom($this->startedAt) : null,
            'completed_at' => $this->completedAt !== null ? Dates::toAtom($this->completedAt) : null,
            'cancelled_at' => $this->cancelledAt !== null ? Dates::toAtom($this->cancelledAt) : null,
            'failed_at' => $this->failedAt !== null ? Dates::toAtom($this->failedAt) : null,
            'cancel_requested' => $this->cancelRequested ? '1' : '0',
            'failed_step' => $this->failedStep !== null ? (string) $this->failedStep : '',
            'failed_job_id' => $this->failedJobId ?? '',
            'failure_reason' => $this->failureReason ?? '',
            'metadata' => json_encode($this->metadata, JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $originRaw = $data['origin'] ?? null;
        if (is_string($originRaw)) {
            $decoded = json_decode($originRaw, true);
            $originRaw = is_array($decoded) ? $decoded : ['package' => (string) ($data['origin_package'] ?? ''), 'version' => (string) ($data['origin_version'] ?? '')];
        }
        if (!is_array($originRaw)) {
            $originRaw = [
                'package' => (string) ($data['origin_package'] ?? ''),
                'version' => (string) ($data['origin_version'] ?? '0'),
            ];
        }
        $metadata = $data['metadata'] ?? [];
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($metadata)) {
            $metadata = [];
        }

        /** @var array<string, mixed> $originRaw */
        /** @var array<string, mixed> $metadata */
        return new self(
            chainId: (string) $data['chain_id'],
            origin: Origin::fromArray($originRaw),
            context: ExecutionContext::fromArray([
                'network_id' => isset($data['network_id']) ? (int) $data['network_id'] : 1,
                'site_id' => isset($data['site_id']) ? (int) $data['site_id'] : 1,
                'scope' => (string) ($data['scope'] ?? 'site'),
            ]),
            state: ChainState::from((string) $data['state']),
            currentStep: (int) $data['current_step'],
            totalSteps: (int) $data['total_steps'],
            failurePolicy: ChainFailurePolicy::from((string) $data['failure_policy']),
            createdAt: Dates::fromAtom((string) $data['created_at']),
            startedAt: self::optionalDate($data['started_at'] ?? null),
            completedAt: self::optionalDate($data['completed_at'] ?? null),
            cancelledAt: self::optionalDate($data['cancelled_at'] ?? null),
            failedAt: self::optionalDate($data['failed_at'] ?? null),
            cancelRequested: (string) ($data['cancel_requested'] ?? '0') === '1',
            failedStep: self::optionalInt($data['failed_step'] ?? null),
            failedJobId: self::optionalString($data['failed_job_id'] ?? null),
            failureReason: self::optionalString($data['failure_reason'] ?? null),
            metadata: $metadata,
        );
    }

    private static function optionalDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return Dates::fromAtom($value);
    }

    private static function optionalInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private static function optionalString(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
