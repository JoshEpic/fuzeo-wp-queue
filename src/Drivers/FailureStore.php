<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Drivers;

use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\JobState;
use Fuzeo\Queue\Retry\AttemptRecord;
use Fuzeo\Queue\Retention\PruneResult;
use Fuzeo\Queue\Retention\RetentionPolicy;

/**
 * Failure history and retry/dead settlement. Not part of the core QueueDriver transport contract.
 */
interface FailureStore
{
    public function settleOutcome(
        Reservation $reservation,
        AttemptRecord $record,
        JobState $nextState,
        ?\DateTimeImmutable $availableAt,
    ): void;

    /**
     * @return list<AttemptRecord>
     */
    public function attemptsFor(string $jobId): array;

    /**
     * @return list<Envelope>
     */
    public function listStopped(int $limit = 50, int $offset = 0): array;

    public function revive(string $jobId): Envelope;

    public function job(string $jobId): Envelope;

    public function prune(RetentionPolicy $policy, int $batchSize = 500): PruneResult;
}
