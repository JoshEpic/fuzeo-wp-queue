<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Drivers\MySql;

use Fuzeo\Queue\Config\Config;
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
use Fuzeo\Queue\Exceptions\AmbiguousAckException;
use Fuzeo\Queue\Exceptions\DriverException;
use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\JobState;
use Fuzeo\Queue\Jobs\QueueName;
use Fuzeo\Queue\Persistence\Connection;
use Fuzeo\Queue\Persistence\DatabaseMigrationRepository;
use Fuzeo\Queue\Persistence\Schema;
use Fuzeo\Queue\Persistence\SchemaOwner;
use Fuzeo\Queue\Retry\AttemptRecord;
use Fuzeo\Queue\Retention\PruneResult;
use Fuzeo\Queue\Retention\RetentionPolicy;
use Fuzeo\Queue\Support\Dates;
use Fuzeo\Queue\Support\SystemClock;

/**
 * Durable InnoDB driver. Reservation uses SELECT ... FOR UPDATE SKIP LOCKED.
 */
final class MySqlDriver implements QueueDriver, FailureStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Clock $clock = new SystemClock(),
        private readonly Config $config = new Config(),
    ) {
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    public function enqueue(Envelope $envelope): EnqueuedJob
    {
        if ($envelope->state !== JobState::Pending) {
            throw new DriverException('Only pending envelopes can be enqueued.');
        }

        $json = $this->encodeEnvelope($envelope);
        $now = Dates::toAtom($this->clock->now());
        $this->connection->execute(
            'INSERT INTO ' . $this->jobs() . ' (
                job_id, envelope_version, job_type, job_schema_version, queue, priority, state, envelope,
                available_at, attempt, network_id, site_id, scope, origin_package, origin_version, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $envelope->jobId,
                $envelope->envelopeVersion,
                $envelope->jobType,
                $envelope->schemaVersion,
                $envelope->queue,
                $envelope->priority,
                JobState::Pending->value,
                $json,
                $this->date($envelope->availableAt),
                $envelope->attempt,
                $envelope->context->networkId,
                $envelope->context->siteId,
                $envelope->context->scope->value,
                $envelope->origin->package,
                $envelope->origin->version,
                $this->date($envelope->createdAt),
                $this->date(Dates::fromAtom($now)),
            ]
        );

        return new EnqueuedJob($envelope);
    }

    public function reserve(ReserveRequest $request): ?Reservation
    {
        if (!$this->connection->supportsSkipLocked()) {
            throw new DriverException(
                'MySQL/MariaDB must support FOR UPDATE SKIP LOCKED (MySQL 8.0.1+ or MariaDB 10.6+).'
            );
        }
        $this->assertSchemaCompatible();

        $now = $this->clock->now();
        $leaseExpires = $now->add(new \DateInterval('PT' . $request->leaseSeconds . 'S'));
        $token = ReservationToken::generate();

        $this->connection->begin();
        try {
            $row = $this->connection->selectOne(
                'SELECT * FROM ' . $this->jobs() . '
                 WHERE `queue` = ?
                   AND (
                        (`state` = ? AND `available_at` <= ?)
                     OR (`state` = ? AND `lease_expires_at` IS NOT NULL AND `lease_expires_at` <= ?)
                   )
                 ORDER BY `priority` DESC, `available_at` ASC, `job_id` ASC
                 LIMIT 1
                 FOR UPDATE SKIP LOCKED',
                [
                    $request->queue,
                    JobState::Pending->value,
                    $this->date($now),
                    JobState::Reserved->value,
                    $this->date($now),
                ]
            );

            if ($row === null) {
                $this->connection->commit();

                return null;
            }

            $envelope = $this->hydrate($row);
            if ($envelope->attempt >= $envelope->maxAttempts) {
                $this->deadLetterLockedRow($envelope, $now);
                $this->connection->commit();

                return null;
            }
            $attempt = $envelope->attempt + 1;
            $reserved = $envelope->withAttempt($attempt);
            if ($reserved->state === JobState::Pending) {
                $reserved = $reserved->withState(JobState::Reserved);
            } elseif ($reserved->state === JobState::Reserved) {
                $reserved = $reserved->withState(JobState::Pending)->withState(JobState::Reserved);
            }

            $affected = $this->connection->execute(
                'UPDATE ' . $this->jobs() . '
                 SET `state` = ?, `attempt` = ?, `reserved_at` = ?, `lease_expires_at` = ?,
                     `reservation_token` = ?, `worker_id` = ?, `updated_at` = ?, `envelope` = ?
                 WHERE `job_id` = ?',
                [
                    JobState::Reserved->value,
                    $attempt,
                    $this->date($now),
                    $this->date($leaseExpires),
                    $token->value,
                    $request->workerId,
                    $this->date($now),
                    $this->encodeEnvelope($reserved),
                    $reserved->jobId,
                ]
            );

            if ($affected !== 1) {
                $this->connection->rollBack();
                throw new DriverException('Failed to persist reservation for job ' . $envelope->jobId . '.');
            }

            $this->connection->commit();

            return new Reservation($reserved, $token, $now, $leaseExpires, $request->workerId);
        } catch (\Throwable $exception) {
            $this->connection->rollBack();
            throw $exception;
        }
    }

    public function acknowledge(Reservation $reservation): void
    {
        $now = $this->clock->now();
        $completed = $reservation->envelope->withState(JobState::Completed);
        $affected = $this->connection->execute(
            'UPDATE ' . $this->jobs() . '
             SET `state` = ?, `completed_at` = ?, `updated_at` = ?, `envelope` = ?,
                 `reservation_token` = NULL, `lease_expires_at` = NULL
             WHERE `job_id` = ? AND `reservation_token` = ? AND `state` = ?',
            [
                JobState::Completed->value,
                $this->date($now),
                $this->date($now),
                $this->encodeEnvelope($completed),
                $reservation->envelope->jobId,
                $reservation->token->value,
                JobState::Reserved->value,
            ]
        );

        if ($affected !== 1) {
            throw new DriverException(
                'Reservation token is not the active owner of job ' . $reservation->envelope->jobId . '.'
            );
        }
    }

    public function release(Reservation $reservation, ReleaseOptions $options): void
    {
        $now = $this->clock->now();
        $availableAt = $options->availableAt ?? $now->add(new \DateInterval('PT' . $options->delaySeconds . 'S'));
        $released = $reservation->envelope->withState(JobState::Pending)->withAvailableAt($availableAt);
        $affected = $this->connection->execute(
            'UPDATE ' . $this->jobs() . '
             SET `state` = ?, `available_at` = ?, `updated_at` = ?, `envelope` = ?,
                 `reservation_token` = NULL, `lease_expires_at` = NULL, `reserved_at` = NULL, `worker_id` = NULL
             WHERE `job_id` = ? AND `reservation_token` = ? AND `state` = ?',
            [
                JobState::Pending->value,
                $this->date($availableAt),
                $this->date($now),
                $this->encodeEnvelope($released),
                $reservation->envelope->jobId,
                $reservation->token->value,
                JobState::Reserved->value,
            ]
        );

        if ($affected !== 1) {
            throw new DriverException(
                'Reservation token is not the active owner of job ' . $reservation->envelope->jobId . '.'
            );
        }
    }

    public function fail(Reservation $reservation, Failure $failure): void
    {
        $now = $this->clock->now();
        $failed = $reservation->envelope->withState(JobState::Failed);
        $affected = $this->connection->execute(
            'UPDATE ' . $this->jobs() . '
             SET `state` = ?, `failed_at` = ?, `updated_at` = ?, `envelope` = ?,
                 `failure_class` = ?, `failure_message` = ?,
                 `reservation_token` = NULL, `lease_expires_at` = NULL
             WHERE `job_id` = ? AND `reservation_token` = ? AND `state` = ?',
            [
                JobState::Failed->value,
                $this->date($now),
                $this->date($now),
                $this->encodeEnvelope($failed),
                $failure->class,
                $failure->message,
                $reservation->envelope->jobId,
                $reservation->token->value,
                JobState::Reserved->value,
            ]
        );

        if ($affected !== 1) {
            throw new DriverException(
                'Reservation token is not the active owner of job ' . $reservation->envelope->jobId . '.'
            );
        }
    }

    public function settleOutcome(
        Reservation $reservation,
        AttemptRecord $record,
        JobState $nextState,
        ?\DateTimeImmutable $availableAt,
    ): void {
        if ($nextState !== JobState::Pending && $nextState !== JobState::Dead) {
            throw new DriverException('settleOutcome only supports pending retry or dead.');
        }

        $now = $this->clock->now();
        $this->connection->begin();
        try {
            if ($nextState === JobState::Pending) {
                $when = $availableAt ?? $now;
                $updated = $reservation->envelope->withState(JobState::Pending)->withAvailableAt($when);
                $affected = $this->connection->execute(
                    'UPDATE ' . $this->jobs() . '
                     SET `state` = ?, `available_at` = ?, `updated_at` = ?, `envelope` = ?,
                         `failure_class` = ?, `failure_message` = ?,
                         `reservation_token` = NULL, `lease_expires_at` = NULL, `reserved_at` = NULL, `worker_id` = NULL
                     WHERE `job_id` = ? AND `reservation_token` = ? AND `state` = ?',
                    [
                        JobState::Pending->value,
                        $this->date($when),
                        $this->date($now),
                        $this->encodeEnvelope($updated),
                        $record->failureClass,
                        $record->sanitizedMessage,
                        $reservation->envelope->jobId,
                        $reservation->token->value,
                        JobState::Reserved->value,
                    ]
                );
            } else {
                $updated = $reservation->envelope->withState(JobState::Dead);
                $affected = $this->connection->execute(
                    'UPDATE ' . $this->jobs() . '
                     SET `state` = ?, `failed_at` = ?, `updated_at` = ?, `envelope` = ?,
                         `failure_class` = ?, `failure_message` = ?,
                         `reservation_token` = NULL, `lease_expires_at` = NULL
                     WHERE `job_id` = ? AND `reservation_token` = ? AND `state` = ?',
                    [
                        JobState::Dead->value,
                        $this->date($now),
                        $this->date($now),
                        $this->encodeEnvelope($updated),
                        $record->failureClass,
                        $record->sanitizedMessage,
                        $reservation->envelope->jobId,
                        $reservation->token->value,
                        JobState::Reserved->value,
                    ]
                );
            }

            if ($affected !== 1) {
                $this->connection->rollBack();
                throw new DriverException(
                    'Reservation token is not the active owner of job ' . $reservation->envelope->jobId . '.'
                );
            }

            $this->insertAttempt($record);
            $this->connection->commit();
        } catch (\Throwable $exception) {
            $this->connection->rollBack();
            throw $exception;
        }
    }

    public function attemptsFor(string $jobId): array
    {
        $rows = $this->connection->select(
            'SELECT * FROM ' . $this->attempts() . ' WHERE `job_id` = ? ORDER BY `attempt` ASC, `failed_at` ASC',
            [$jobId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->hydrateAttempt($row);
        }

        return $out;
    }

    public function listStopped(int $limit = 50, int $offset = 0): array
    {
        $rows = $this->connection->select(
            'SELECT * FROM ' . $this->jobs() . '
             WHERE `state` IN (?, ?)
             ORDER BY COALESCE(`failed_at`, `updated_at`) DESC
             LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset,
            [JobState::Dead->value, JobState::Failed->value]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->hydrate($row);
        }

        return $out;
    }

    public function revive(string $jobId): Envelope
    {
        $now = $this->clock->now();
        $this->connection->begin();
        try {
            $row = $this->connection->selectOne(
                'SELECT * FROM ' . $this->jobs() . ' WHERE `job_id` = ? FOR UPDATE',
                [$jobId]
            );
            if ($row === null) {
                $this->connection->rollBack();
                throw new DriverException('Unknown job ' . $jobId . '.');
            }
            $current = $this->hydrate($row);
            if ($current->state !== JobState::Dead && $current->state !== JobState::Failed) {
                $this->connection->rollBack();
                throw new DriverException('Job ' . $jobId . ' is not dead or failed.');
            }
            $replay = (int) ($current->metadata['_replay'] ?? 0) + 1;
            $metadata = $current->metadata;
            $metadata['_replay'] = $replay;
            $revived = $current
                ->withState(JobState::Pending)
                ->resetAttempts()
                ->withAvailableAt($now)
                ->withMetadata($metadata);
            $affected = $this->connection->execute(
                'UPDATE ' . $this->jobs() . '
                 SET `state` = ?, `available_at` = ?, `updated_at` = ?, `envelope` = ?, `attempt` = 0,
                     `failed_at` = NULL, `reservation_token` = NULL, `lease_expires_at` = NULL,
                     `reserved_at` = NULL, `worker_id` = NULL
                 WHERE `job_id` = ? AND `state` IN (?, ?)',
                [
                    JobState::Pending->value,
                    $this->date($now),
                    $this->date($now),
                    $this->encodeEnvelope($revived),
                    $jobId,
                    JobState::Dead->value,
                    JobState::Failed->value,
                ]
            );
            if ($affected !== 1) {
                $this->connection->rollBack();
                throw new DriverException('Unable to revive job ' . $jobId . '.');
            }
            $this->connection->commit();

            return $revived;
        } catch (\Throwable $exception) {
            $this->connection->rollBack();
            throw $exception;
        }
    }

    public function prune(RetentionPolicy $policy, int $batchSize = 500): PruneResult
    {
        $batchSize = max(1, min(5000, $batchSize));
        $now = $this->clock->now();
        $completed = $this->pruneState(
            JobState::Completed,
            'completed_at',
            $policy->completedBefore($now),
            $batchSize
        );
        $dead = $this->pruneStates(
            [JobState::Dead, JobState::Failed],
            $policy->deadBefore($now),
            $batchSize
        );

        return new PruneResult($completed['jobs'], $dead['jobs'], $completed['attempts'] + $dead['attempts']);
    }

    public function retryingCount(): int
    {
        $row = $this->connection->selectOne(
            'SELECT COUNT(*) AS `c` FROM ' . $this->jobs() . ' WHERE `state` = ? AND `available_at` > ?',
            [JobState::Pending->value, $this->date($this->clock->now())]
        );

        return (int) ($row['c'] ?? 0);
    }

    public function extendLease(Reservation $reservation, \DateInterval $extension): Reservation
    {
        $now = $this->clock->now();
        $expires = $now->add($extension);
        $affected = $this->connection->execute(
            'UPDATE ' . $this->jobs() . '
             SET `lease_expires_at` = ?, `updated_at` = ?
             WHERE `job_id` = ? AND `reservation_token` = ? AND `state` = ?',
            [
                $this->date($expires),
                $this->date($now),
                $reservation->envelope->jobId,
                $reservation->token->value,
                JobState::Reserved->value,
            ]
        );

        if ($affected !== 1) {
            throw new DriverException(
                'Cannot extend lease; reservation token is not the active owner of job '
                . $reservation->envelope->jobId . '.'
            );
        }

        return $reservation->withLease($expires);
    }

    public function size(string $queue): int
    {
        QueueName::assertValid($queue);
        $now = $this->date($this->clock->now());
        $row = $this->connection->selectOne(
            'SELECT COUNT(*) AS `c` FROM ' . $this->jobs() . '
             WHERE `queue` = ? AND (
                (`state` = ? AND `available_at` <= ?)
             OR (`state` = ? AND `lease_expires_at` IS NOT NULL AND `lease_expires_at` <= ?)
             )',
            [$queue, JobState::Pending->value, $now, JobState::Reserved->value, $now]
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * @return array<string, int>
     */
    public function countsByState(): array
    {
        $rows = $this->connection->select('SELECT `state`, COUNT(*) AS `c` FROM ' . $this->jobs() . ' GROUP BY `state`');
        $counts = [];
        foreach ($rows as $row) {
            $state = (string) ($row['state'] ?? '');
            $counts[$state] = (int) ($row['c'] ?? 0);
        }

        return $counts;
    }

    /**
     * @return list<array{queue: string, pending: int}>
     */
    public function queueSizes(): array
    {
        $now = $this->date($this->clock->now());
        $rows = $this->connection->select(
            'SELECT `queue`, COUNT(*) AS `c` FROM ' . $this->jobs() . '
             WHERE (`state` = ? AND `available_at` <= ?)
                OR (`state` = ? AND `lease_expires_at` IS NOT NULL AND `lease_expires_at` <= ?)
             GROUP BY `queue` ORDER BY `queue` ASC',
            [JobState::Pending->value, $now, JobState::Reserved->value, $now]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'queue' => (string) ($row['queue'] ?? ''),
                'pending' => (int) ($row['c'] ?? 0),
            ];
        }

        return $out;
    }

    public function health(): DriverHealth
    {
        $details = [
            'skip_locked' => $this->connection->supportsSkipLocked(),
            'prefix' => $this->connection->prefix(),
        ];
        try {
            if (!$this->connection->ping()) {
                return new DriverHealth(false, 'mysql', $details, 'Database ping failed.');
            }
            $this->connection->selectOne('SELECT `job_id` FROM ' . $this->jobs() . ' LIMIT 1');
            $this->connection->selectOne('SELECT `worker_id` FROM ' . $this->workers() . ' LIMIT 1');
            $details['states'] = $this->countsByState();

            return new DriverHealth(true, 'mysql', $details);
        } catch (\Throwable $exception) {
            return new DriverHealth(false, 'mysql', $details, $exception->getMessage());
        }
    }

    public function capabilities(): DriverCapabilities
    {
        return new DriverCapabilities(
            priorities: true,
            delayedJobs: true,
            durable: true,
        );
    }

    public function get(string $jobId): Envelope
    {
        $row = $this->connection->selectOne('SELECT * FROM ' . $this->jobs() . ' WHERE `job_id` = ?', [$jobId]);
        if ($row === null) {
            throw new DriverException('Unknown job ' . $jobId . '.');
        }

        return $this->hydrate($row);
    }

    public function job(string $jobId): Envelope
    {
        return $this->get($jobId);
    }

    /**
     * ACK that must not be treated as success when the database is unreachable.
     */
    public function acknowledgeOrAmbiguous(Reservation $reservation): void
    {
        try {
            $this->acknowledge($reservation);
        } catch (DriverException $exception) {
            if ($this->isConnectivityFailure($exception)) {
                throw new AmbiguousAckException(
                    'ACK could not be persisted. The job must not be treated as complete and may run again.',
                    0,
                    $exception
                );
            }
            throw $exception;
        }
    }

    private function jobs(): string
    {
        return Schema::quoteTable($this->connection->prefix(), Schema::JOBS);
    }

    private function workers(): string
    {
        return Schema::quoteTable($this->connection->prefix(), Schema::WORKERS);
    }

    private function attempts(): string
    {
        return Schema::quoteTable($this->connection->prefix(), Schema::ATTEMPTS);
    }

    private function assertSchemaCompatible(): void
    {
        $version = (new DatabaseMigrationRepository($this->connection))->currentVersion();
        if ($version !== SchemaOwner::CURRENT_VERSION) {
            throw new DriverException(
                'Queue schema version is ' . $version . '; this worker requires '
                . SchemaOwner::CURRENT_VERSION . '. Restart workers after upgrading fuzeowp/queue.'
            );
        }
    }

    private function deadLetterLockedRow(Envelope $envelope, \DateTimeImmutable $now): void
    {
        $dead = $envelope->state === JobState::Dead ? $envelope : $envelope->withState(JobState::Dead);
        $this->connection->execute(
            'UPDATE ' . $this->jobs() . '
             SET `state` = ?, `failed_at` = ?, `updated_at` = ?, `envelope` = ?,
                 `reservation_token` = NULL, `lease_expires_at` = NULL, `reserved_at` = NULL, `worker_id` = NULL
             WHERE `job_id` = ?',
            [
                JobState::Dead->value,
                $this->date($now),
                $this->date($now),
                $this->encodeEnvelope($dead),
                $envelope->jobId,
            ]
        );
    }

    private function insertAttempt(AttemptRecord $record): void
    {
        $this->connection->execute(
            'INSERT INTO ' . $this->attempts() . ' (
                attempt_id, job_id, attempt, outcome, job_type, queue, worker_id, reservation_token,
                origin_package, network_id, site_id, scope, failure_class, sanitized_message, sanitized_trace,
                will_retry, next_available_at, terminal_reason, failed_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $record->attemptId,
                $record->jobId,
                $record->attempt,
                $record->outcome,
                $record->jobType,
                $record->queue,
                $record->workerId,
                $record->reservationToken,
                $record->originPackage,
                $record->networkId,
                $record->siteId,
                $record->scope,
                $record->failureClass,
                $record->sanitizedMessage,
                $record->sanitizedTrace,
                $record->willRetry ? 1 : 0,
                $record->nextAvailableAt !== null ? $this->date($record->nextAvailableAt) : null,
                $record->terminalReason,
                $this->date($record->failedAt),
            ]
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateAttempt(array $row): AttemptRecord
    {
        $next = $row['next_available_at'] ?? null;
        $failedAt = $row['failed_at'] ?? null;
        if (!is_string($failedAt) || $failedAt === '') {
            throw new DriverException('Attempt row is missing failed_at.');
        }

        return new AttemptRecord(
            attemptId: (string) ($row['attempt_id'] ?? ''),
            jobId: (string) ($row['job_id'] ?? ''),
            attempt: (int) ($row['attempt'] ?? 0),
            outcome: (string) ($row['outcome'] ?? ''),
            jobType: (string) ($row['job_type'] ?? ''),
            queue: (string) ($row['queue'] ?? ''),
            workerId: isset($row['worker_id']) && is_string($row['worker_id']) ? $row['worker_id'] : null,
            reservationToken: isset($row['reservation_token']) && is_string($row['reservation_token']) ? $row['reservation_token'] : null,
            originPackage: (string) ($row['origin_package'] ?? ''),
            networkId: (int) ($row['network_id'] ?? 0),
            siteId: (int) ($row['site_id'] ?? 0),
            scope: (string) ($row['scope'] ?? ''),
            failureClass: isset($row['failure_class']) && is_string($row['failure_class']) ? $row['failure_class'] : null,
            sanitizedMessage: (string) ($row['sanitized_message'] ?? ''),
            sanitizedTrace: (string) ($row['sanitized_trace'] ?? ''),
            willRetry: (int) ($row['will_retry'] ?? 0) === 1,
            nextAvailableAt: is_string($next) && $next !== '' ? Dates::fromAtom(str_replace(' ', 'T', $next)) : null,
            terminalReason: isset($row['terminal_reason']) && is_string($row['terminal_reason']) ? $row['terminal_reason'] : null,
            failedAt: Dates::fromAtom(str_replace(' ', 'T', $failedAt)),
        );
    }

    /**
     * @return array{jobs: int, attempts: int}
     */
    private function pruneState(JobState $state, string $timestampColumn, \DateTimeImmutable $before, int $limit): array
    {
        if (!in_array($timestampColumn, ['completed_at', 'failed_at', 'updated_at'], true)) {
            throw new DriverException('Invalid prune timestamp column.');
        }
        $rows = $this->connection->select(
            'SELECT `job_id` FROM ' . $this->jobs() . '
             WHERE `state` = ? AND `' . $timestampColumn . '` IS NOT NULL AND `' . $timestampColumn . '` <= ?
             LIMIT ' . $limit,
            [$state->value, $this->date($before)]
        );

        return $this->deleteJobRows($rows);
    }

    /**
     * @param list<JobState> $states
     * @return array{jobs: int, attempts: int}
     */
    private function pruneStates(array $states, \DateTimeImmutable $before, int $limit): array
    {
        $placeholders = implode(',', array_fill(0, count($states), '?'));
        $params = [];
        foreach ($states as $state) {
            $params[] = $state->value;
        }
        $params[] = $this->date($before);
        $rows = $this->connection->select(
            'SELECT `job_id` FROM ' . $this->jobs() . '
             WHERE `state` IN (' . $placeholders . ')
               AND COALESCE(`failed_at`, `updated_at`) <= ?
             LIMIT ' . $limit,
            $params
        );

        return $this->deleteJobRows($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{jobs: int, attempts: int}
     */
    private function deleteJobRows(array $rows): array
    {
        if ($rows === []) {
            return ['jobs' => 0, 'attempts' => 0];
        }
        $ids = [];
        foreach ($rows as $row) {
            $id = $row['job_id'] ?? null;
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return ['jobs' => 0, 'attempts' => 0];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $attempts = $this->connection->execute(
            'DELETE FROM ' . $this->attempts() . ' WHERE `job_id` IN (' . $placeholders . ')',
            $ids
        );
        $jobs = $this->connection->execute(
            'DELETE FROM ' . $this->jobs() . ' WHERE `job_id` IN (' . $placeholders . ')',
            $ids
        );

        return ['jobs' => $jobs, 'attempts' => $attempts];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Envelope
    {
        $json = $row['envelope'] ?? null;
        if (!is_string($json) || $json === '') {
            throw new DriverException('Persisted job is missing envelope JSON.');
        }
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new DriverException('Persisted job envelope JSON is corrupt.', 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new DriverException('Persisted job envelope JSON must be an object.');
        }
        if (isset($decoded['envelope_version']) && is_numeric($decoded['envelope_version'])) {
            $decoded['envelope_version'] = (int) $decoded['envelope_version'];
        }
        foreach (['schema_version', 'priority', 'attempt', 'max_attempts', 'timeout_seconds', 'network_id', 'site_id'] as $intKey) {
            if (isset($decoded[$intKey]) && is_numeric($decoded[$intKey])) {
                $decoded[$intKey] = (int) $decoded[$intKey];
            }
        }

        return Envelope::fromArray($decoded);
    }

    private function encodeEnvelope(Envelope $envelope): string
    {
        try {
            $json = json_encode($envelope->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $exception) {
            throw new DriverException('Unable to encode envelope JSON.', 0, $exception);
        }
        $max = max($this->config->maxPayloadBytes * 2, $this->config->maxPayloadBytes + 4096);
        if (strlen($json) > $max) {
            throw new DriverException(
                'Envelope JSON exceeds the configured storage limit of ' . $max . ' bytes. Queue identifiers, not large blobs.'
            );
        }

        return $json;
    }

    private function date(\DateTimeImmutable $value): string
    {
        return Dates::utc($value)->format('Y-m-d H:i:s.u');
    }

    private function isConnectivityFailure(DriverException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'gone away')
            || str_contains($message, 'lost connection')
            || str_contains($message, 'connection lost')
            || str_contains($message, 'server has gone');
    }
}
