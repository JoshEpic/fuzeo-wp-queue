<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Orchestration;

use Fuzeo\Queue\Jobs\Job;
use Fuzeo\Queue\Jobs\JobType;
use Fuzeo\Queue\Jobs\QueueName;
use Fuzeo\Queue\Retry\Retryable;

/**
 * JSON-safe description of a job that will be dispatched later.
 */
final class JobBlueprint
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $metadata
     * @param list<string> $tags
     */
    public function __construct(
        public readonly string $jobType,
        public readonly int $schemaVersion,
        public readonly array $payload,
        public readonly string $queue,
        public readonly int $priority = 0,
        public readonly int $maxAttempts = 3,
        public readonly int $timeoutSeconds = 60,
        public readonly array $metadata = [],
        public readonly array $tags = [],
        public readonly ?string $uniqueKey = null,
        public readonly ?int $uniqueTtlSeconds = null,
        public readonly ?\Fuzeo\Queue\Retry\RetryPolicy $retryPolicy = null,
    ) {
        JobType::assertValid($this->jobType);
        QueueName::assertValid($this->queue);
    }

    public static function fromJob(Job $job, string $queue, int $defaultMaxAttempts, int $defaultTimeout): self
    {
        $policy = null;
        $max = $defaultMaxAttempts;
        if ($job instanceof Retryable) {
            $policy = $job->retryPolicy();
            $max = $policy->maxAttempts;
        }
        $unique = $job instanceof \Fuzeo\Queue\Jobs\UniqueJob ? $job->uniqueKey() : null;
        $metadata = [];
        if ($policy !== null) {
            $metadata['_retry'] = $policy->toArray();
        }

        return new self(
            jobType: JobType::normalize($job::type()),
            schemaVersion: $job::schemaVersion(),
            payload: $job->payload(),
            queue: QueueName::normalize($queue),
            maxAttempts: $max,
            timeoutSeconds: $defaultTimeout,
            metadata: $metadata,
            uniqueKey: $unique,
            retryPolicy: $policy,
        );
    }
}
