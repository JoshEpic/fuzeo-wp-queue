<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Schedule;

use Fuzeo\Queue\Jobs\ExecutionContext;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Support\Dates;

final class ScheduleDefinition
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $scheduleId,
        public readonly string $name,
        public readonly Origin $origin,
        public readonly string $jobType,
        public readonly int $schemaVersion,
        public readonly array $payload,
        public readonly string $queue,
        public readonly int $priority,
        public readonly ExecutionContext $context,
        public readonly ScheduleExpression $expression,
        public readonly string $timezone,
        public readonly \DateTimeImmutable $nextRunAt,
        public readonly ?\DateTimeImmutable $lastRunAt,
        public readonly ?string $lastOccurrenceId,
        public readonly bool $enabled,
        public readonly OverlapPolicy $overlap,
        public readonly CatchUpPolicy $catchUp,
        public readonly ?string $blockedReason,
        public readonly array $metadata,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
        public readonly ?string $lastResult = null,
    ) {
    }

    public function withNextRun(\DateTimeImmutable $next, ?\DateTimeImmutable $lastRun, ?string $occurrenceId, ?string $lastResult = null): self
    {
        return $this->cloneWith([
            'nextRunAt' => Dates::utc($next),
            'lastRunAt' => $lastRun !== null ? Dates::utc($lastRun) : null,
            'lastOccurrenceId' => $occurrenceId,
            'lastResult' => $lastResult ?? $this->lastResult,
            'updatedAt' => $this->updatedAt,
        ]);
    }

    public function withEnabled(bool $enabled): self
    {
        return $this->cloneWith(['enabled' => $enabled, 'blockedReason' => $enabled ? null : $this->blockedReason]);
    }

    public function withBlocked(?string $reason): self
    {
        return $this->cloneWith(['blockedReason' => $reason]);
    }

    public function withUpdatedAt(\DateTimeImmutable $at): self
    {
        return $this->cloneWith(['updatedAt' => Dates::utc($at)]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schedule_id' => $this->scheduleId,
            'name' => $this->name,
            'origin' => $this->origin->toArray(),
            'job_type' => $this->jobType,
            'job_schema_version' => $this->schemaVersion,
            'payload' => $this->payload,
            'queue' => $this->queue,
            'priority' => $this->priority,
            'network_id' => $this->context->networkId,
            'site_id' => $this->context->siteId,
            'scope' => $this->context->scope->value,
            'expression_type' => $this->expression->type->value,
            'expression_value' => $this->expression->value,
            'timezone' => $this->timezone,
            'next_run_at' => Dates::toAtom($this->nextRunAt),
            'last_run_at' => $this->lastRunAt !== null ? Dates::toAtom($this->lastRunAt) : null,
            'last_occurrence_id' => $this->lastOccurrenceId,
            'enabled' => $this->enabled,
            'overlap_policy' => $this->overlap->value,
            'catch_up_policy' => $this->catchUp->value,
            'blocked_reason' => $this->blockedReason,
            'metadata' => $this->metadata,
            'created_at' => Dates::toAtom($this->createdAt),
            'updated_at' => Dates::toAtom($this->updatedAt),
            'last_result' => $this->lastResult,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $origin = $data['origin'] ?? null;
        if (!is_array($origin)) {
            throw new \Fuzeo\Queue\Exceptions\QueueException('Schedule origin must be an object.');
        }
        $payload = $data['payload'] ?? [];
        $metadata = $data['metadata'] ?? [];
        if (!is_array($payload) || !is_array($metadata)) {
            throw new \Fuzeo\Queue\Exceptions\QueueException('Schedule payload and metadata must be maps.');
        }
        $type = ScheduleExpressionType::from((string) $data['expression_type']);
        $expr = new ScheduleExpression($type, (string) $data['expression_value']);

        return new self(
            scheduleId: (string) $data['schedule_id'],
            name: (string) $data['name'],
            origin: Origin::fromArray($origin),
            jobType: (string) $data['job_type'],
            schemaVersion: (int) $data['job_schema_version'],
            payload: $payload,
            queue: (string) $data['queue'],
            priority: (int) $data['priority'],
            context: ExecutionContext::fromArray([
                'network_id' => (int) $data['network_id'],
                'site_id' => (int) $data['site_id'],
                'scope' => (string) $data['scope'],
            ]),
            expression: $expr,
            timezone: (string) $data['timezone'],
            nextRunAt: Dates::fromAtom((string) $data['next_run_at']),
            lastRunAt: isset($data['last_run_at']) && is_string($data['last_run_at']) ? Dates::fromAtom($data['last_run_at']) : null,
            lastOccurrenceId: isset($data['last_occurrence_id']) && is_string($data['last_occurrence_id']) ? $data['last_occurrence_id'] : null,
            enabled: (bool) $data['enabled'],
            overlap: OverlapPolicy::from((string) $data['overlap_policy']),
            catchUp: CatchUpPolicy::from((string) $data['catch_up_policy']),
            blockedReason: isset($data['blocked_reason']) && is_string($data['blocked_reason']) ? $data['blocked_reason'] : null,
            metadata: $metadata,
            createdAt: Dates::fromAtom((string) $data['created_at']),
            updatedAt: Dates::fromAtom((string) $data['updated_at']),
            lastResult: isset($data['last_result']) && is_string($data['last_result']) ? $data['last_result'] : null,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function cloneWith(array $overrides): self
    {
        return new self(
            scheduleId: $this->scheduleId,
            name: $this->name,
            origin: $this->origin,
            jobType: $this->jobType,
            schemaVersion: $this->schemaVersion,
            payload: $this->payload,
            queue: $this->queue,
            priority: $this->priority,
            context: $this->context,
            expression: $this->expression,
            timezone: $this->timezone,
            nextRunAt: $overrides['nextRunAt'] ?? $this->nextRunAt,
            lastRunAt: array_key_exists('lastRunAt', $overrides) ? $overrides['lastRunAt'] : $this->lastRunAt,
            lastOccurrenceId: array_key_exists('lastOccurrenceId', $overrides) ? $overrides['lastOccurrenceId'] : $this->lastOccurrenceId,
            enabled: $overrides['enabled'] ?? $this->enabled,
            overlap: $this->overlap,
            catchUp: $this->catchUp,
            blockedReason: array_key_exists('blockedReason', $overrides) ? $overrides['blockedReason'] : $this->blockedReason,
            metadata: $this->metadata,
            createdAt: $this->createdAt,
            updatedAt: $overrides['updatedAt'] ?? $this->updatedAt,
            lastResult: array_key_exists('lastResult', $overrides) ? $overrides['lastResult'] : $this->lastResult,
        );
    }
}
