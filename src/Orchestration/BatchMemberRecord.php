<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Orchestration;

final class BatchMemberRecord
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $metadata
     * @param list<string> $tags
     */
    public function __construct(
        public readonly string $batchId,
        public readonly int $memberIndex,
        public readonly string $jobType,
        public readonly int $schemaVersion,
        public readonly array $payload,
        public readonly string $queue,
        public readonly int $priority,
        public readonly int $maxAttempts,
        public readonly int $timeoutSeconds,
        public readonly MemberStatus $status,
        public readonly array $metadata = [],
        public readonly array $tags = [],
        public readonly ?string $uniqueKey = null,
        public readonly ?string $jobId = null,
    ) {
    }
}
