<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Retry;

use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\JobState;
use Fuzeo\Queue\Support\Dates;
use Fuzeo\Queue\Support\Ulid;

final class AttemptRecord
{
    public function __construct(
        public readonly string $attemptId,
        public readonly string $jobId,
        public readonly int $attempt,
        public readonly string $outcome,
        public readonly string $jobType,
        public readonly string $queue,
        public readonly ?string $workerId,
        public readonly ?string $reservationToken,
        public readonly string $originPackage,
        public readonly int $networkId,
        public readonly int $siteId,
        public readonly string $scope,
        public readonly ?string $failureClass,
        public readonly string $sanitizedMessage,
        public readonly string $sanitizedTrace,
        public readonly bool $willRetry,
        public readonly ?\DateTimeImmutable $nextAvailableAt,
        public readonly ?string $terminalReason,
        public readonly \DateTimeImmutable $failedAt,
    ) {
    }

    public static function fromFailure(
        Envelope $envelope,
        \Throwable $throwable,
        RetryDecision $decision,
        string $sanitizedMessage,
        string $sanitizedTrace,
        \DateTimeImmutable $failedAt,
        ?string $workerId,
        ?string $reservationToken,
    ): self {
        $outcome = $decision->willRetry ? 'retry_scheduled' : JobState::Dead->value;

        return new self(
            attemptId: Ulid::generate(),
            jobId: $envelope->jobId,
            attempt: $envelope->attempt,
            outcome: $outcome,
            jobType: $envelope->jobType,
            queue: $envelope->queue,
            workerId: $workerId,
            reservationToken: $reservationToken,
            originPackage: $envelope->origin->package,
            networkId: $envelope->context->networkId,
            siteId: $envelope->context->siteId,
            scope: $envelope->context->scope->value,
            failureClass: $throwable::class,
            sanitizedMessage: $sanitizedMessage,
            sanitizedTrace: $sanitizedTrace,
            willRetry: $decision->willRetry,
            nextAvailableAt: $decision->availableAt,
            terminalReason: $decision->terminalReason,
            failedAt: $failedAt,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'attempt_id' => $this->attemptId,
            'job_id' => $this->jobId,
            'attempt' => $this->attempt,
            'outcome' => $this->outcome,
            'job_type' => $this->jobType,
            'queue' => $this->queue,
            'worker_id' => $this->workerId,
            'reservation_token' => $this->reservationToken,
            'origin_package' => $this->originPackage,
            'network_id' => $this->networkId,
            'site_id' => $this->siteId,
            'scope' => $this->scope,
            'failure_class' => $this->failureClass,
            'sanitized_message' => $this->sanitizedMessage,
            'sanitized_trace' => $this->sanitizedTrace,
            'will_retry' => $this->willRetry,
            'next_available_at' => $this->nextAvailableAt !== null ? Dates::toAtom($this->nextAvailableAt) : null,
            'terminal_reason' => $this->terminalReason,
            'failed_at' => Dates::toAtom($this->failedAt),
        ];
    }
}
