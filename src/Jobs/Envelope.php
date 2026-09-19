<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Jobs;

use Fuzeo\Queue\Exceptions\QueueException;
use Fuzeo\Queue\Exceptions\UnsupportedEnvelopeException;
use Fuzeo\Queue\Retry\RetryPolicy;
use Fuzeo\Queue\Support\Dates;
use Fuzeo\Queue\Support\Ulid;

/**
 * Canonical versioned job envelope. Field names are persistence contracts.
 *
 * envelope_version is the envelope format. schema_version is the job payload format.
 */
final class Envelope
{
    public const VERSION = 1;

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $metadata
     * @param list<string> $tags
     */
    public function __construct(
        public readonly string $jobId,
        public readonly int $envelopeVersion,
        public readonly string $jobType,
        public readonly int $schemaVersion,
        public readonly array $payload,
        public readonly string $queue,
        public readonly int $priority,
        public readonly int $attempt,
        public readonly int $maxAttempts,
        public readonly int $timeoutSeconds,
        public readonly \DateTimeImmutable $availableAt,
        public readonly ExecutionContext $context,
        public readonly Origin $origin,
        public readonly ?string $correlationId,
        public readonly ?string $batchId,
        public readonly ?string $chainId,
        public readonly ?string $parentJobId,
        public readonly ?string $idempotencyKey,
        public readonly ?string $uniqueKey,
        public readonly array $metadata,
        public readonly array $tags,
        public readonly \DateTimeImmutable $createdAt,
        public readonly JobState $state = JobState::Pending,
    ) {
        if ($this->envelopeVersion < 1) {
            throw new UnsupportedEnvelopeException('Envelope version must be a positive integer.');
        }
        if ($this->envelopeVersion > self::VERSION) {
            throw new UnsupportedEnvelopeException(
                'Unsupported envelope version ' . $this->envelopeVersion . '. This runtime reads version ' . self::VERSION . '.'
            );
        }
        if (!Ulid::isValid($this->jobId)) {
            throw new QueueException('job_id must be a ULID.');
        }
        JobType::assertValid($this->jobType);
        QueueName::assertValid($this->queue);
        if ($this->schemaVersion < 1) {
            throw new QueueException('schema_version must be a positive integer.');
        }
        if ($this->attempt < 0 || $this->maxAttempts < 1 || $this->timeoutSeconds < 1) {
            throw new QueueException('attempt, max_attempts, and timeout_seconds are invalid.');
        }
        $this->assertOptionalId('correlation_id', $this->correlationId);
        $this->assertOptionalId('batch_id', $this->batchId);
        $this->assertOptionalId('chain_id', $this->chainId);
        $this->assertOptionalId('parent_job_id', $this->parentJobId);
        $this->assertOptionalKey('idempotency_key', $this->idempotencyKey);
        $this->assertOptionalKey('unique_key', $this->uniqueKey);
        foreach ($this->tags as $tag) {
            if (!is_string($tag) || $tag === '' || strlen($tag) > 64) {
                throw new QueueException('Each tag must be a non-empty string of 64 characters or fewer.');
            }
        }
        if (count($this->tags) > 32) {
            throw new QueueException('Envelope may contain at most 32 tags.');
        }
    }

    public function withState(JobState $state): self
    {
        JobStateMachine::transition($this->state, $state);

        return $this->cloneWith(['state' => $state]);
    }

    public function withAttempt(int $attempt): self
    {
        if ($attempt < $this->attempt) {
            throw new QueueException('Job attempt count cannot decrease.');
        }

        return $this->cloneWith(['attempt' => $attempt]);
    }

    public function resetAttempts(): self
    {
        return $this->cloneWith(['attempt' => 0]);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function withMetadata(array $metadata): self
    {
        return $this->cloneWith(['metadata' => $metadata]);
    }

    public function retryPolicy(): RetryPolicy
    {
        $data = $this->metadata['_retry'] ?? [];
        if (!is_array($data)) {
            $data = [];
        }
        /** @var array<string, mixed> $data */
        $data['max_attempts'] = $this->maxAttempts;

        return RetryPolicy::fromArray($data, $this->maxAttempts);
    }

    public function withAvailableAt(\DateTimeImmutable $availableAt): self
    {
        return $this->cloneWith(['availableAt' => Dates::utc($availableAt)]);
    }

    public function executionClass(): \Fuzeo\Queue\Execution\ExecutionClass
    {
        $meta = $this->metadata['_execution'] ?? null;
        if (is_array($meta) && isset($meta['class']) && is_string($meta['class'])) {
            return \Fuzeo\Queue\Execution\ExecutionClass::tryFrom($meta['class'])
                ?? \Fuzeo\Queue\Execution\ExecutionClass::Standard;
        }

        return \Fuzeo\Queue\Execution\ExecutionClass::Standard;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'envelope_version' => $this->envelopeVersion,
            'job_id' => $this->jobId,
            'job_type' => $this->jobType,
            'schema_version' => $this->schemaVersion,
            'payload' => $this->payload,
            'queue' => $this->queue,
            'priority' => $this->priority,
            'attempt' => $this->attempt,
            'max_attempts' => $this->maxAttempts,
            'timeout_seconds' => $this->timeoutSeconds,
            'available_at' => Dates::toAtom($this->availableAt),
            'network_id' => $this->context->networkId,
            'site_id' => $this->context->siteId,
            'scope' => $this->context->scope->value,
            'origin' => $this->origin->toArray(),
            'correlation_id' => $this->correlationId,
            'batch_id' => $this->batchId,
            'chain_id' => $this->chainId,
            'parent_job_id' => $this->parentJobId,
            'idempotency_key' => $this->idempotencyKey,
            'unique_key' => $this->uniqueKey,
            'metadata' => $this->metadata,
            'tags' => $this->tags,
            'created_at' => Dates::toAtom($this->createdAt),
            'state' => $this->state->value,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $version = $data['envelope_version'] ?? null;
        if (!is_int($version)) {
            throw new UnsupportedEnvelopeException('Envelope is missing integer envelope_version.');
        }
        if ($version > self::VERSION) {
            throw new UnsupportedEnvelopeException(
                'Unsupported envelope version ' . $version . '. This runtime reads version ' . self::VERSION . '.'
            );
        }
        if ($version < 1) {
            throw new UnsupportedEnvelopeException('Envelope version ' . $version . ' cannot be executed.');
        }

        $jobId = self::stringField($data, 'job_id');
        $jobType = self::stringField($data, 'job_type');
        $schemaVersion = self::intField($data, 'schema_version');
        $payload = $data['payload'] ?? null;
        if (!is_array($payload)) {
            throw new QueueException('Envelope payload must be an object/map.');
        }
        $queue = self::stringField($data, 'queue');
        $priority = self::intField($data, 'priority');
        $attempt = self::intField($data, 'attempt');
        $maxAttempts = self::intField($data, 'max_attempts');
        $timeoutSeconds = self::intField($data, 'timeout_seconds');
        $availableAt = Dates::fromAtom(self::stringField($data, 'available_at'));
        $createdAt = Dates::fromAtom(self::stringField($data, 'created_at'));

        $origin = $data['origin'] ?? null;
        if (!is_array($origin)) {
            throw new QueueException('Envelope origin must be an object.');
        }

        $stateValue = $data['state'] ?? JobState::Pending->value;
        if (!is_string($stateValue)) {
            throw new QueueException('Envelope state must be a string.');
        }
        $state = JobState::tryFrom($stateValue);
        if ($state === null) {
            throw new QueueException('Unknown job state "' . $stateValue . '".');
        }

        $tags = $data['tags'] ?? [];
        if (!is_array($tags)) {
            throw new QueueException('Envelope tags must be a list of strings.');
        }
        $metadata = $data['metadata'] ?? [];
        if (!is_array($metadata)) {
            throw new QueueException('Envelope metadata must be a map.');
        }

        /** @var array<string, mixed> $payload */
        /** @var array<string, mixed> $metadata */
        /** @var list<string> $tags */
        return new self(
            jobId: $jobId,
            envelopeVersion: $version,
            jobType: $jobType,
            schemaVersion: $schemaVersion,
            payload: $payload,
            queue: $queue,
            priority: $priority,
            attempt: $attempt,
            maxAttempts: $maxAttempts,
            timeoutSeconds: $timeoutSeconds,
            availableAt: $availableAt,
            context: ExecutionContext::fromArray([
                'network_id' => $data['network_id'] ?? null,
                'site_id' => $data['site_id'] ?? null,
                'scope' => $data['scope'] ?? ExecutionScope::Site->value,
            ]),
            origin: Origin::fromArray($origin),
            correlationId: self::optionalString($data, 'correlation_id'),
            batchId: self::optionalString($data, 'batch_id'),
            chainId: self::optionalString($data, 'chain_id'),
            parentJobId: self::optionalString($data, 'parent_job_id'),
            idempotencyKey: self::optionalString($data, 'idempotency_key'),
            uniqueKey: self::optionalString($data, 'unique_key'),
            metadata: $metadata,
            tags: array_values($tags),
            createdAt: $createdAt,
            state: $state,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function cloneWith(array $overrides): self
    {
        return new self(
            jobId: $overrides['jobId'] ?? $this->jobId,
            envelopeVersion: $this->envelopeVersion,
            jobType: $this->jobType,
            schemaVersion: $this->schemaVersion,
            payload: $this->payload,
            queue: $this->queue,
            priority: $this->priority,
            attempt: $overrides['attempt'] ?? $this->attempt,
            maxAttempts: $this->maxAttempts,
            timeoutSeconds: $this->timeoutSeconds,
            availableAt: $overrides['availableAt'] ?? $this->availableAt,
            context: $this->context,
            origin: $this->origin,
            correlationId: $this->correlationId,
            batchId: $this->batchId,
            chainId: $this->chainId,
            parentJobId: $this->parentJobId,
            idempotencyKey: $this->idempotencyKey,
            uniqueKey: $this->uniqueKey,
            metadata: $overrides['metadata'] ?? $this->metadata,
            tags: $this->tags,
            createdAt: $this->createdAt,
            state: $overrides['state'] ?? $this->state,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function stringField(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new QueueException('Envelope field ' . $key . ' must be a non-empty string.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function intField(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw new QueueException('Envelope field ' . $key . ' must be an integer.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new QueueException('Envelope field ' . $key . ' must be a string or null.');
        }

        return $value === '' ? null : $value;
    }

    private function assertOptionalId(string $field, ?string $value): void
    {
        if ($value === null) {
            return;
        }
        if (!Ulid::isValid($value)) {
            throw new QueueException($field . ' must be a ULID when present.');
        }
    }

    private function assertOptionalKey(string $field, ?string $value): void
    {
        if ($value === null) {
            return;
        }
        if (strlen($value) > 191) {
            throw new QueueException($field . ' must be 191 characters or fewer.');
        }
    }
}
