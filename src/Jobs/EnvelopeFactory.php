<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Jobs;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Contracts\ExecutionContextResolver;
use Fuzeo\Queue\Serialization\PayloadSerializer;
use Fuzeo\Queue\Support\Dates;
use Fuzeo\Queue\Support\SystemClock;
use Fuzeo\Queue\Support\Ulid;

final class EnvelopeFactory
{
    public function __construct(
        private readonly PayloadSerializer $serializer,
        private readonly JobRegistry $registry,
        private readonly ExecutionContextResolver $contextResolver,
        private readonly Clock $clock = new SystemClock(),
        private readonly int $defaultMaxAttempts = 3,
        private readonly int $defaultTimeoutSeconds = 60,
    ) {
    }

    public function make(Job $job, DispatchOptions $options): Envelope
    {
        $type = JobType::normalize($job::type());
        $registered = $this->registry->get($type);

        if ($job::schemaVersion() !== $registered->schemaVersion) {
            throw new \Fuzeo\Queue\Exceptions\QueueException(
                'Dispatched schema version ' . $job::schemaVersion()
                . ' does not match registered schema version ' . $registered->schemaVersion
                . ' for job type ' . $type . '.'
            );
        }

        $payload = $this->serializer->normalize($job->payload());
        $now = $this->clock->now();
        $availableAt = $options->availableAt !== null ? Dates::utc($options->availableAt) : $now;
        $context = $options->context ?? $this->contextResolver->current();
        $origin = $options->origin ?? $registered->origin;

        return new Envelope(
            jobId: Ulid::generate(self::timestampMs($now)),
            envelopeVersion: Envelope::VERSION,
            jobType: $type,
            schemaVersion: $registered->schemaVersion,
            payload: $payload,
            queue: QueueName::normalize($options->queue ?? QueueName::DEFAULT),
            priority: $options->priority,
            attempt: 0,
            maxAttempts: $options->maxAttempts ?? $this->defaultMaxAttempts,
            timeoutSeconds: $options->timeoutSeconds ?? $this->defaultTimeoutSeconds,
            availableAt: $availableAt,
            context: $context,
            origin: $origin,
            correlationId: $options->correlationId,
            batchId: $options->batchId,
            chainId: $options->chainId,
            parentJobId: $options->parentJobId,
            idempotencyKey: $options->idempotencyKey,
            uniqueKey: $options->uniqueKey,
            metadata: $this->serializer->normalize($options->metadata),
            tags: $options->tags,
            createdAt: $now,
            state: JobState::Pending,
        );
    }

    private static function timestampMs(\DateTimeImmutable $now): int
    {
        return ((int) $now->format('U')) * 1000 + (int) $now->format('v');
    }
}
