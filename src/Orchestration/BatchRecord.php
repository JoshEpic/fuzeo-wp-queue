<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Orchestration;

use Fuzeo\Queue\Jobs\ExecutionContext;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Support\Dates;

final class BatchRecord
{
    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed>|null $thenPayload
     * @param array<string, mixed>|null $catchPayload
     */
    public function __construct(
        public readonly string $batchId,
        public readonly Origin $origin,
        public readonly ExecutionContext $context,
        public readonly BatchState $state,
        public readonly int $totalJobs,
        public readonly int $completedJobs,
        public readonly int $failedJobs,
        public readonly int $cancelledJobs,
        public readonly BatchFailurePolicy $failurePolicy,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?string $name = null,
        public readonly ?\DateTimeImmutable $startedAt = null,
        public readonly ?\DateTimeImmutable $completedAt = null,
        public readonly ?\DateTimeImmutable $cancelledAt = null,
        public readonly ?\DateTimeImmutable $failedAt = null,
        public readonly bool $cancelRequested = false,
        public readonly ?string $thenJobType = null,
        public readonly ?array $thenPayload = null,
        public readonly ?string $thenQueue = null,
        public readonly ?string $catchJobType = null,
        public readonly ?array $catchPayload = null,
        public readonly ?string $catchQueue = null,
        public readonly ?string $thenJobId = null,
        public readonly ?string $catchJobId = null,
        public readonly array $metadata = [],
    ) {
    }

    public function progressPercent(): int
    {
        if ($this->totalJobs < 1) {
            return 100;
        }

        $done = $this->completedJobs + $this->failedJobs + $this->cancelledJobs;

        return (int) floor(100 * $done / $this->totalJobs);
    }

    public function allTerminal(): bool
    {
        return ($this->completedJobs + $this->failedJobs + $this->cancelledJobs) >= $this->totalJobs;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'batch_id' => $this->batchId,
            'name' => $this->name ?? '',
            'origin' => json_encode($this->origin->toArray(), JSON_THROW_ON_ERROR),
            'origin_package' => $this->origin->package,
            'origin_version' => $this->origin->version,
            'network_id' => (string) $this->context->networkId,
            'site_id' => (string) $this->context->siteId,
            'scope' => $this->context->scope->value,
            'state' => $this->state->value,
            'total_jobs' => (string) $this->totalJobs,
            'completed_jobs' => (string) $this->completedJobs,
            'failed_jobs' => (string) $this->failedJobs,
            'cancelled_jobs' => (string) $this->cancelledJobs,
            'failure_policy' => $this->failurePolicy->value,
            'created_at' => Dates::toAtom($this->createdAt),
            'started_at' => $this->startedAt !== null ? Dates::toAtom($this->startedAt) : '',
            'completed_at' => $this->completedAt !== null ? Dates::toAtom($this->completedAt) : '',
            'cancelled_at' => $this->cancelledAt !== null ? Dates::toAtom($this->cancelledAt) : '',
            'failed_at' => $this->failedAt !== null ? Dates::toAtom($this->failedAt) : '',
            'cancel_requested' => $this->cancelRequested ? '1' : '0',
            'then_job_type' => $this->thenJobType ?? '',
            'then_payload' => $this->thenPayload !== null ? json_encode($this->thenPayload, JSON_THROW_ON_ERROR) : '',
            'then_queue' => $this->thenQueue ?? '',
            'catch_job_type' => $this->catchJobType ?? '',
            'catch_payload' => $this->catchPayload !== null ? json_encode($this->catchPayload, JSON_THROW_ON_ERROR) : '',
            'catch_queue' => $this->catchQueue ?? '',
            'then_job_id' => $this->thenJobId ?? '',
            'catch_job_id' => $this->catchJobId ?? '',
            'metadata' => json_encode($this->metadata, JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $originRaw = $data['origin'] ?? null;
        if (is_string($originRaw) && $originRaw !== '') {
            $decoded = json_decode($originRaw, true);
            $originRaw = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($originRaw)) {
            $originRaw = [
                'package' => (string) ($data['origin_package'] ?? ''),
                'version' => (string) ($data['origin_version'] ?? '0'),
            ];
        }
        $metadata = self::decodeMap($data['metadata'] ?? []);
        $thenPayload = self::decodeMapOrNull($data['then_payload'] ?? null);
        $catchPayload = self::decodeMapOrNull($data['catch_payload'] ?? null);

        /** @var array<string, mixed> $originRaw */
        return new self(
            batchId: (string) $data['batch_id'],
            origin: Origin::fromArray($originRaw),
            context: ExecutionContext::fromArray([
                'network_id' => (int) ($data['network_id'] ?? 1),
                'site_id' => (int) ($data['site_id'] ?? 1),
                'scope' => (string) ($data['scope'] ?? 'site'),
            ]),
            state: BatchState::from((string) $data['state']),
            totalJobs: (int) $data['total_jobs'],
            completedJobs: (int) $data['completed_jobs'],
            failedJobs: (int) $data['failed_jobs'],
            cancelledJobs: (int) $data['cancelled_jobs'],
            failurePolicy: BatchFailurePolicy::from((string) $data['failure_policy']),
            createdAt: Dates::fromAtom((string) $data['created_at']),
            name: self::optionalString($data['name'] ?? null),
            startedAt: self::optionalDate($data['started_at'] ?? null),
            completedAt: self::optionalDate($data['completed_at'] ?? null),
            cancelledAt: self::optionalDate($data['cancelled_at'] ?? null),
            failedAt: self::optionalDate($data['failed_at'] ?? null),
            cancelRequested: (string) ($data['cancel_requested'] ?? '0') === '1',
            thenJobType: self::optionalString($data['then_job_type'] ?? null),
            thenPayload: $thenPayload,
            thenQueue: self::optionalString($data['then_queue'] ?? null),
            catchJobType: self::optionalString($data['catch_job_type'] ?? null),
            catchPayload: $catchPayload,
            catchQueue: self::optionalString($data['catch_queue'] ?? null),
            thenJobId: self::optionalString($data['then_job_id'] ?? null),
            catchJobId: self::optionalString($data['catch_job_id'] ?? null),
            metadata: $metadata,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeMap(mixed $value): array
    {
        if (is_array($value)) {
            /** @var array<string, mixed> $value */
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodeMapOrNull(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        $map = self::decodeMap($value);

        return $map;
    }

    private static function optionalDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return Dates::fromAtom($value);
    }

    private static function optionalString(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
