<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Schedule;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Jobs\ExecutionContext;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Persistence\Connection;
use Fuzeo\Queue\Persistence\Schema;
use Fuzeo\Queue\Support\Dates;
use Fuzeo\Queue\Support\SystemClock;

final class MysqlScheduleStore implements ScheduleStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function save(ScheduleDefinition $schedule): void
    {
        $payload = json_encode($schedule->payload, JSON_THROW_ON_ERROR);
        $metadata = json_encode($schedule->metadata, JSON_THROW_ON_ERROR);
        $this->connection->execute(
            'INSERT INTO ' . $this->schedules() . ' (
                schedule_id, name, origin_package, origin_version, job_type, job_schema_version, payload,
                queue, priority, network_id, site_id, scope, expression_type, expression_value, timezone,
                next_run_at, last_run_at, last_occurrence_id, last_result, enabled, overlap_policy,
                catch_up_policy, blocked_reason, metadata, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                origin_version = VALUES(origin_version),
                job_type = VALUES(job_type),
                job_schema_version = VALUES(job_schema_version),
                payload = VALUES(payload),
                queue = VALUES(queue),
                priority = VALUES(priority),
                expression_type = VALUES(expression_type),
                expression_value = VALUES(expression_value),
                timezone = VALUES(timezone),
                next_run_at = VALUES(next_run_at),
                last_run_at = VALUES(last_run_at),
                last_occurrence_id = VALUES(last_occurrence_id),
                last_result = VALUES(last_result),
                enabled = VALUES(enabled),
                overlap_policy = VALUES(overlap_policy),
                catch_up_policy = VALUES(catch_up_policy),
                blocked_reason = VALUES(blocked_reason),
                metadata = VALUES(metadata),
                updated_at = VALUES(updated_at)',
            [
                $schedule->scheduleId,
                $schedule->name,
                $schedule->origin->package,
                $schedule->origin->version,
                $schedule->jobType,
                $schedule->schemaVersion,
                $payload,
                $schedule->queue,
                $schedule->priority,
                $schedule->context->networkId,
                $schedule->context->siteId,
                $schedule->context->scope->value,
                $schedule->expression->type->value,
                $schedule->expression->value,
                $schedule->timezone,
                Dates::toDatabase($schedule->nextRunAt),
                $schedule->lastRunAt !== null ? Dates::toDatabase($schedule->lastRunAt) : null,
                $schedule->lastOccurrenceId,
                $schedule->lastResult,
                $schedule->enabled ? 1 : 0,
                $schedule->overlap->value,
                $schedule->catchUp->value,
                $schedule->blockedReason,
                $metadata,
                Dates::toDatabase($schedule->createdAt),
                Dates::toDatabase($schedule->updatedAt),
            ]
        );
    }

    public function get(string $scheduleId): ?ScheduleDefinition
    {
        $row = $this->connection->selectOne(
            'SELECT * FROM ' . $this->schedules() . ' WHERE `schedule_id` = ?',
            [$scheduleId]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function all(): array
    {
        $rows = $this->connection->select('SELECT * FROM ' . $this->schedules() . ' ORDER BY `name` ASC');
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->hydrate($row);
        }

        return $out;
    }

    public function due(\DateTimeImmutable $now, int $limit = 50): array
    {
        $rows = $this->connection->select(
            'SELECT * FROM ' . $this->schedules() . '
             WHERE `enabled` = 1 AND `blocked_reason` IS NULL AND `next_run_at` <= ?
             ORDER BY `next_run_at` ASC LIMIT ' . (int) $limit,
            [Dates::toDatabase($now)]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->hydrate($row);
        }

        return $out;
    }

    public function delete(string $scheduleId): void
    {
        $this->connection->execute('DELETE FROM ' . $this->schedules() . ' WHERE `schedule_id` = ?', [$scheduleId]);
    }

    public function claimOccurrence(
        string $occurrenceId,
        string $scheduleId,
        \DateTimeImmutable $intendedRunAt,
        string $ownerToken,
        \DateTimeImmutable $leaseExpiresAt,
    ): bool {
        $now = $this->clock->now();
        $this->connection->begin();
        try {
            $row = $this->connection->selectOne(
                'SELECT * FROM ' . $this->claims() . ' WHERE `occurrence_id` = ? FOR UPDATE',
                [$occurrenceId]
            );
            if ($row === null) {
                $this->connection->execute(
                    'INSERT INTO ' . $this->claims() . ' (
                        occurrence_id, schedule_id, intended_run_at, owner_token, status, job_id, lease_expires_at, created_at
                    ) VALUES (?, ?, ?, ?, ?, NULL, ?, ?)',
                    [
                        $occurrenceId,
                        $scheduleId,
                        Dates::toDatabase($intendedRunAt),
                        $ownerToken,
                        'claimed',
                        Dates::toDatabase($leaseExpiresAt),
                        Dates::toDatabase($now),
                    ]
                );
                $this->connection->commit();

                return true;
            }
            $status = (string) ($row['status'] ?? '');
            $lease = Dates::fromDatabase((string) $row['lease_expires_at']);
            if ($status === 'dispatched' || $lease > $now) {
                $this->connection->commit();

                return false;
            }
            $this->connection->execute(
                'UPDATE ' . $this->claims() . '
                 SET `owner_token` = ?, `status` = ?, `job_id` = NULL, `lease_expires_at` = ?
                 WHERE `occurrence_id` = ?',
                [$ownerToken, 'claimed', Dates::toDatabase($leaseExpiresAt), $occurrenceId]
            );
            $this->connection->commit();

            return true;
        } catch (\Throwable $exception) {
            $this->connection->rollBack();
            throw $exception;
        }
    }

    public function markDispatched(string $occurrenceId, string $ownerToken, string $jobId): bool
    {
        $affected = $this->connection->execute(
            'UPDATE ' . $this->claims() . '
             SET `status` = ?, `job_id` = ?
             WHERE `occurrence_id` = ? AND `owner_token` = ? AND `status` = ?',
            ['dispatched', $jobId, $occurrenceId, $ownerToken, 'claimed']
        );

        return $affected === 1;
    }

    public function getClaim(string $occurrenceId): ?ScheduleClaim
    {
        $row = $this->connection->selectOne(
            'SELECT * FROM ' . $this->claims() . ' WHERE `occurrence_id` = ?',
            [$occurrenceId]
        );
        if ($row === null) {
            return null;
        }
        $jobId = $row['job_id'] ?? null;

        return new ScheduleClaim(
            (string) $row['occurrence_id'],
            (string) $row['schedule_id'],
            Dates::fromDatabase((string) $row['intended_run_at']),
            (string) $row['owner_token'],
            (string) $row['status'],
            is_string($jobId) && $jobId !== '' ? $jobId : null,
            Dates::fromDatabase((string) $row['lease_expires_at']),
        );
    }

    public function heartbeat(string $schedulerId, array $row): void
    {
        $now = Dates::toDatabase($this->clock->now());
        $this->connection->execute(
            'INSERT INTO ' . $this->schedulerTable() . ' (
                scheduler_id, hostname, pid, started_at, last_heartbeat_at, status, runtime_version
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                last_heartbeat_at = VALUES(last_heartbeat_at),
                status = VALUES(status)',
            [
                $schedulerId,
                (string) ($row['hostname'] ?? ''),
                (int) ($row['pid'] ?? 0),
                (string) ($row['started_at'] ?? $now),
                $now,
                (string) ($row['status'] ?? 'running'),
                (string) ($row['runtime_version'] ?? ''),
            ]
        );
    }

    public function schedulers(): array
    {
        return $this->connection->select('SELECT * FROM ' . $this->schedulerTable() . ' ORDER BY `last_heartbeat_at` DESC');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ScheduleDefinition
    {
        $payload = json_decode((string) ($row['payload'] ?? '{}'), true);
        $metadata = json_decode((string) ($row['metadata'] ?? '{}'), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        if (!is_array($metadata)) {
            $metadata = [];
        }
        $lastRun = $row['last_run_at'] ?? null;
        $blocked = $row['blocked_reason'] ?? null;
        $lastOcc = $row['last_occurrence_id'] ?? null;
        $lastResult = $row['last_result'] ?? null;

        return new ScheduleDefinition(
            scheduleId: (string) $row['schedule_id'],
            name: (string) $row['name'],
            origin: new Origin((string) $row['origin_package'], (string) $row['origin_version']),
            jobType: (string) $row['job_type'],
            schemaVersion: (int) $row['job_schema_version'],
            payload: $payload,
            queue: (string) $row['queue'],
            priority: (int) $row['priority'],
            context: ExecutionContext::fromArray([
                'network_id' => (int) $row['network_id'],
                'site_id' => (int) $row['site_id'],
                'scope' => (string) $row['scope'],
            ]),
            expression: new ScheduleExpression(
                ScheduleExpressionType::from((string) $row['expression_type']),
                (string) $row['expression_value'],
            ),
            timezone: (string) $row['timezone'],
            nextRunAt: Dates::fromDatabase((string) $row['next_run_at']),
            lastRunAt: is_string($lastRun) && $lastRun !== '' ? Dates::fromDatabase($lastRun) : null,
            lastOccurrenceId: is_string($lastOcc) && $lastOcc !== '' ? $lastOcc : null,
            enabled: (int) ($row['enabled'] ?? 0) === 1,
            overlap: OverlapPolicy::from((string) $row['overlap_policy']),
            catchUp: CatchUpPolicy::from((string) $row['catch_up_policy']),
            blockedReason: is_string($blocked) && $blocked !== '' ? $blocked : null,
            metadata: $metadata,
            createdAt: Dates::fromDatabase((string) $row['created_at']),
            updatedAt: Dates::fromDatabase((string) $row['updated_at']),
            lastResult: is_string($lastResult) && $lastResult !== '' ? $lastResult : null,
        );
    }

    private function schedules(): string
    {
        return Schema::quoteTable($this->connection->prefix(), Schema::SCHEDULES);
    }

    private function claims(): string
    {
        return Schema::quoteTable($this->connection->prefix(), Schema::SCHEDULE_CLAIMS);
    }

    private function schedulerTable(): string
    {
        return Schema::quoteTable($this->connection->prefix(), Schema::SCHEDULERS);
    }
}
