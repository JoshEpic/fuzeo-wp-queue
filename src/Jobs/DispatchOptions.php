<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Jobs;

final class DispatchOptions
{
    /**
     * @param array<string, mixed> $metadata
     * @param list<string> $tags
     */
    public function __construct(
        public readonly ?string $queue = null,
        public readonly int $priority = 0,
        public readonly ?\DateTimeImmutable $availableAt = null,
        public readonly ?ExecutionContext $context = null,
        public readonly ?Origin $origin = null,
        public readonly ?string $correlationId = null,
        public readonly ?string $batchId = null,
        public readonly ?string $chainId = null,
        public readonly ?string $parentJobId = null,
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $uniqueKey = null,
        public readonly array $metadata = [],
        public readonly array $tags = [],
        public readonly ?int $maxAttempts = null,
        public readonly ?int $timeoutSeconds = null,
        public readonly ?\Fuzeo\Queue\Retry\RetryPolicy $retryPolicy = null,
    ) {
    }

    public function withQueue(string $queue): self
    {
        return $this->cloneWith(['queue' => $queue]);
    }

    public function withAvailableAt(\DateTimeImmutable $availableAt): self
    {
        return $this->cloneWith(['availableAt' => $availableAt]);
    }

    public function withContext(ExecutionContext $context): self
    {
        return $this->cloneWith(['context' => $context]);
    }

    public function withOrigin(Origin $origin): self
    {
        return $this->cloneWith(['origin' => $origin]);
    }

    public function withPriority(int $priority): self
    {
        return $this->cloneWith(['priority' => $priority]);
    }

    public function withCorrelationId(string $id): self
    {
        return $this->cloneWith(['correlationId' => $id]);
    }

    public function withIdempotencyKey(string $key): self
    {
        return $this->cloneWith(['idempotencyKey' => $key]);
    }

    public function withUniqueKey(string $key): self
    {
        return $this->cloneWith(['uniqueKey' => $key]);
    }

    /**
     * @param list<string> $tags
     */
    public function withTags(array $tags): self
    {
        return $this->cloneWith(['tags' => $tags]);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function withMetadata(array $metadata): self
    {
        return $this->cloneWith(['metadata' => $metadata]);
    }

    public function withMaxAttempts(int $maxAttempts): self
    {
        return $this->cloneWith(['maxAttempts' => $maxAttempts]);
    }

    public function withRetryPolicy(\Fuzeo\Queue\Retry\RetryPolicy $policy): self
    {
        return $this->cloneWith(['retryPolicy' => $policy]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function cloneWith(array $overrides): self
    {
        return new self(
            queue: $overrides['queue'] ?? $this->queue,
            priority: $overrides['priority'] ?? $this->priority,
            availableAt: $overrides['availableAt'] ?? $this->availableAt,
            context: $overrides['context'] ?? $this->context,
            origin: $overrides['origin'] ?? $this->origin,
            correlationId: $overrides['correlationId'] ?? $this->correlationId,
            batchId: $this->batchId,
            chainId: $this->chainId,
            parentJobId: $this->parentJobId,
            idempotencyKey: $overrides['idempotencyKey'] ?? $this->idempotencyKey,
            uniqueKey: $overrides['uniqueKey'] ?? $this->uniqueKey,
            metadata: $overrides['metadata'] ?? $this->metadata,
            tags: $overrides['tags'] ?? $this->tags,
            maxAttempts: $overrides['maxAttempts'] ?? $this->maxAttempts,
            timeoutSeconds: $overrides['timeoutSeconds'] ?? $this->timeoutSeconds,
            retryPolicy: $overrides['retryPolicy'] ?? $this->retryPolicy,
        );
    }
}
