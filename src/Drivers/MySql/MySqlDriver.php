<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Drivers\MySql;

use Fuzeo\Queue\Config\Config;
use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Drivers\DriverCapabilities;
use Fuzeo\Queue\Drivers\DriverHealth;
use Fuzeo\Queue\Drivers\EnqueuedJob;
use Fuzeo\Queue\Drivers\Failure;
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
use Fuzeo\Queue\Persistence\Schema;
use Fuzeo\Queue\Support\Dates;
use Fuzeo\Queue\Support\SystemClock;

/**
 * Durable InnoDB driver. Reservation uses SELECT ... FOR UPDATE SKIP LOCKED.
 */
final class MySqlDriver implements QueueDriver
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
