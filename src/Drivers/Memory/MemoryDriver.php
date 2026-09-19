<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Drivers\Memory;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Drivers\DriverCapabilities;
use Fuzeo\Queue\Drivers\DriverHealth;
use Fuzeo\Queue\Drivers\EnqueuedJob;
use Fuzeo\Queue\Drivers\Failure;
use Fuzeo\Queue\Drivers\FailureStore;
use Fuzeo\Queue\Drivers\QueueDriver;
use Fuzeo\Queue\Drivers\ReleaseOptions;
use Fuzeo\Queue\Drivers\Reservation;
use Fuzeo\Queue\Drivers\ReservationToken;
use Fuzeo\Queue\Drivers\ReserveRequest;
use Fuzeo\Queue\Exceptions\DriverException;
use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\JobState;
use Fuzeo\Queue\Jobs\JobStateMachine;
use Fuzeo\Queue\Jobs\QueueName;
use Fuzeo\Queue\Retry\AttemptRecord;
use Fuzeo\Queue\Retention\PruneResult;
use Fuzeo\Queue\Retention\RetentionPolicy;
use Fuzeo\Queue\Support\SystemClock;

/**
 * In-process driver for tests and local experiments. Not durable across requests.
 */
final class MemoryDriver implements QueueDriver, FailureStore
{
    /** @var array<string, Envelope> */
    private array $jobs = [];

    /** @var array<string, Reservation> */
    private array $reservations = [];

    /** @var array<string, list<AttemptRecord>> */
    private array $attempts = [];

    public function __construct(private readonly Clock $clock = new SystemClock())
    {
    }

    public function enqueue(Envelope $envelope): EnqueuedJob
    {
        if ($envelope->state !== JobState::Pending) {
            throw new DriverException('Only pending envelopes can be enqueued.');
        }

        $this->jobs[$envelope->jobId] = $envelope;

        return new EnqueuedJob($envelope);
    }

    public function reserve(ReserveRequest $request): ?Reservation
    {
        $now = $this->clock->now();
        $this->recoverExpired($now);

        $selected = null;
        foreach ($this->jobs as $envelope) {
            if ($envelope->queue !== $request->queue) {
                continue;
            }
            if ($envelope->state !== JobState::Pending) {
                continue;
            }
            if ($envelope->availableAt > $now) {
                continue;
            }
            if ($selected === null || $this->isBetter($envelope, $selected)) {
                $selected = $envelope;
            }
        }

        if ($selected === null) {
            return null;
        }

        if ($selected->attempt >= $selected->maxAttempts) {
            $this->jobs[$selected->jobId] = $selected->withState(JobState::Dead);
            return null;
        }

        $reserved = $selected->withAttempt($selected->attempt + 1)->withState(JobState::Reserved);
        $token = ReservationToken::generate();
        $leaseExpires = $now->add(new \DateInterval('PT' . $request->leaseSeconds . 'S'));
        $reservation = new Reservation($reserved, $token, $now, $leaseExpires, $request->workerId);
        $this->jobs[$reserved->jobId] = $reserved;
        $this->reservations[$reserved->jobId] = $reservation;

        return $reservation;
    }

    public function acknowledge(Reservation $reservation): void
    {
        $current = $this->requireOwnership($reservation);
        $this->jobs[$current->envelope->jobId] = $current->envelope->withState(JobState::Completed);
        unset($this->reservations[$current->envelope->jobId]);
    }

    public function release(Reservation $reservation, ReleaseOptions $options): void
    {
        $current = $this->requireOwnership($reservation);
        $now = $this->clock->now();
        $availableAt = $options->availableAt ?? $now->add(new \DateInterval('PT' . $options->delaySeconds . 'S'));
        $released = $current->envelope
            ->withState(JobState::Pending)
            ->withAvailableAt($availableAt);
        $this->jobs[$released->jobId] = $released;
        unset($this->reservations[$released->jobId]);
    }

    public function fail(Reservation $reservation, Failure $failure): void
    {
        $current = $this->requireOwnership($reservation);
        $failed = $current->envelope->withState(JobState::Failed);
        $this->jobs[$failed->jobId] = $failed;
        unset($this->reservations[$failed->jobId]);
        unset($failure);
    }

    public function settleOutcome(
        Reservation $reservation,
        AttemptRecord $record,
        JobState $nextState,
        ?\DateTimeImmutable $availableAt,
    ): void {
        $current = $this->requireOwnership($reservation);
        if ($nextState !== JobState::Pending && $nextState !== JobState::Dead) {
            throw new DriverException('settleOutcome only supports pending retry or dead.');
        }
        $updated = $current->envelope->withState($nextState);
        if ($nextState === JobState::Pending) {
            $updated = $updated->withAvailableAt($availableAt ?? $this->clock->now());
        }
        $this->jobs[$updated->jobId] = $updated;
        unset($this->reservations[$updated->jobId]);
        $this->attempts[$updated->jobId][] = $record;
    }

    public function attemptsFor(string $jobId): array
    {
        return $this->attempts[$jobId] ?? [];
    }

    public function listStopped(int $limit = 50, int $offset = 0): array
    {
        $stopped = [];
        foreach ($this->jobs as $envelope) {
            if ($envelope->state === JobState::Dead || $envelope->state === JobState::Failed) {
                $stopped[] = $envelope;
            }
        }

        return array_slice($stopped, $offset, $limit);
    }

    public function revive(string $jobId): Envelope
    {
        if (!isset($this->jobs[$jobId])) {
            throw new DriverException('Unknown job ' . $jobId . '.');
        }
        $current = $this->jobs[$jobId];
        if ($current->state !== JobState::Dead && $current->state !== JobState::Failed) {
            throw new DriverException('Job ' . $jobId . ' is not dead or failed.');
        }
        $replay = (int) ($current->metadata['_replay'] ?? 0) + 1;
        $metadata = $current->metadata;
        $metadata['_replay'] = $replay;
        $revived = $current
            ->withState(JobState::Pending)
            ->resetAttempts()
            ->withAvailableAt($this->clock->now())
            ->withMetadata($metadata);
        $this->jobs[$jobId] = $revived;

        return $revived;
    }

    public function prune(RetentionPolicy $policy, int $batchSize = 500): PruneResult
    {
        $now = $this->clock->now();
        $completedBefore = $policy->completedBefore($now);
        $deadBefore = $policy->deadBefore($now);
        $completed = 0;
        $dead = 0;
        $attemptsDeleted = 0;
        foreach ($this->jobs as $jobId => $envelope) {
            if ($completed >= $batchSize && $dead >= $batchSize) {
                break;
            }
            if ($envelope->state === JobState::Completed && $envelope->createdAt <= $completedBefore && $completed < $batchSize) {
                unset($this->jobs[$jobId]);
                $attemptsDeleted += count($this->attempts[$jobId] ?? []);
                unset($this->attempts[$jobId]);
                $completed++;
            } elseif (
                ($envelope->state === JobState::Dead || $envelope->state === JobState::Failed)
                && $envelope->createdAt <= $deadBefore
                && $dead < $batchSize
            ) {
                unset($this->jobs[$jobId]);
                $attemptsDeleted += count($this->attempts[$jobId] ?? []);
                unset($this->attempts[$jobId]);
                $dead++;
            }
        }

        return new PruneResult($completed, $dead, $attemptsDeleted);
    }

    public function extendLease(Reservation $reservation, \DateInterval $extension): Reservation
    {
        $current = $this->requireOwnership($reservation);
        $now = $this->clock->now();
        JobStateMachine::transition($current->envelope->state, JobState::Reserved);
        $extended = $current->withLease($now->add($extension));
        $this->reservations[$extended->envelope->jobId] = $extended;

        return $extended;
    }

    public function size(string $queue): int
    {
        QueueName::assertValid($queue);
        $now = $this->clock->now();
        $this->recoverExpired($now);
        $count = 0;
        foreach ($this->jobs as $envelope) {
            if ($envelope->queue === $queue && $envelope->state === JobState::Pending && $envelope->availableAt <= $now) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array<string, int>
     */
    public function countsByState(): array
    {
        $counts = [];
        foreach ($this->jobs as $envelope) {
            $counts[$envelope->state->value] = ($counts[$envelope->state->value] ?? 0) + 1;
        }

        return $counts;
    }

    public function retryingCount(): int
    {
        $now = $this->clock->now();
        $count = 0;
        foreach ($this->jobs as $envelope) {
            if ($envelope->state === JobState::Pending && $envelope->availableAt > $now) {
                $count++;
            }
        }

        return $count;
    }

    public function health(): DriverHealth
    {
        return new DriverHealth(true, 'memory', [
            'jobs' => count($this->jobs),
            'reservations' => count($this->reservations),
        ]);
    }

    public function capabilities(): DriverCapabilities
    {
        return new DriverCapabilities(
            priorities: true,
            delayedJobs: true,
            durable: false,
        );
    }

    /**
     * @return list<Envelope>
     */
    public function all(): array
    {
        return array_values($this->jobs);
    }

    public function get(string $jobId): Envelope
    {
        if (!isset($this->jobs[$jobId])) {
            throw new DriverException('Unknown job ' . $jobId . '.');
        }

        return $this->jobs[$jobId];
    }

    public function job(string $jobId): Envelope
    {
        return $this->get($jobId);
    }

    private function recoverExpired(\DateTimeImmutable $now): void
    {
        foreach ($this->reservations as $jobId => $reservation) {
            if (!$reservation->isExpired($now)) {
                continue;
            }
            $envelope = $reservation->envelope;
            if ($envelope->attempt >= $envelope->maxAttempts) {
                $this->jobs[$jobId] = $envelope->withState(JobState::Dead);
            } else {
                $this->jobs[$jobId] = $envelope->withState(JobState::Pending);
            }
            unset($this->reservations[$jobId]);
        }
    }

    private function requireOwnership(Reservation $reservation): Reservation
    {
        $jobId = $reservation->envelope->jobId;
        $current = $this->reservations[$jobId] ?? null;
        if ($current === null || !$current->token->equals($reservation->token)) {
            throw new DriverException(
                'Reservation token is not the active owner of job ' . $jobId . '. A stale worker cannot ACK recovered work.'
            );
        }

        if ($current->envelope->state !== JobState::Reserved) {
            throw new DriverException('Job ' . $jobId . ' is not reserved.');
        }

        return $current;
    }

    private function isBetter(Envelope $candidate, Envelope $current): bool
    {
        if ($candidate->priority !== $current->priority) {
            return $candidate->priority > $current->priority;
        }

        return $candidate->availableAt < $current->availableAt
            || ($candidate->availableAt == $current->availableAt && $candidate->jobId < $current->jobId);
    }
}
