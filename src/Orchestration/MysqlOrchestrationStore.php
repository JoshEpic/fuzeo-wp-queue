<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Orchestration;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Jobs\ExecutionContext;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Persistence\Connection;
use Fuzeo\Queue\Persistence\Schema;
use Fuzeo\Queue\Support\Dates;
use Fuzeo\Queue\Support\SystemClock;

final class MysqlOrchestrationStore implements OrchestrationStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function createChain(ChainRecord $chain, array $steps): void
    {
        $this->connection->begin();
        try {
            $this->insertChain($chain);
            foreach ($steps as $step) {
                $this->insertStep($step);
            }
            $this->connection->commit();
        } catch (\Throwable $exception) {
            $this->connection->rollBack();
            throw $exception;
        }
    }

    public function getChain(string $chainId): ?ChainRecord
    {
        $row = $this->connection->selectOne('SELECT * FROM ' . $this->chains() . ' WHERE `chain_id` = ?', [$chainId]);

        return $row === null ? null : $this->hydrateChain($row);
    }

    public function getStep(string $chainId, int $stepNumber): ?ChainStepRecord
    {
        $row = $this->connection->selectOne(
            'SELECT * FROM ' . $this->chainStepsTable() . ' WHERE `chain_id` = ? AND `step_number` = ?',
            [$chainId, $stepNumber]
        );

        return $row === null ? null : $this->hydrateStep($row);
    }

    public function steps(string $chainId): array
    {
        $rows = $this->connection->select(
            'SELECT * FROM ' . $this->chainStepsTable() . ' WHERE `chain_id` = ? ORDER BY `step_number` ASC',
            [$chainId]
        );

        return array_map(fn (array $row): ChainStepRecord => $this->hydrateStep($row), $rows);
    }

    public function markStepDispatched(string $chainId, int $stepNumber, string $jobId): void
    {
        $this->connection->execute(
            'UPDATE ' . $this->chainStepsTable() . ' SET `status` = ?, `job_id` = ? WHERE `chain_id` = ? AND `step_number` = ?',
            [MemberStatus::Dispatched->value, $jobId, $chainId, $stepNumber]
        );
    }

    public function markStepStatus(string $chainId, int $stepNumber, MemberStatus $status): void
    {
        $this->connection->execute(
            'UPDATE ' . $this->chainStepsTable() . ' SET `status` = ? WHERE `chain_id` = ? AND `step_number` = ?',
            [$status->value, $chainId, $stepNumber]
        );
    }

    public function activateChain(string $chainId): bool
    {
        $now = Dates::toDatabase($this->clock->now());
        $n = $this->connection->execute(
            'UPDATE ' . $this->chains() . ' SET `state` = ?, `started_at` = COALESCE(`started_at`, ?)
             WHERE `chain_id` = ? AND `state` IN (?, ?)',
            [ChainState::Active->value, $now, $chainId, ChainState::Pending->value, ChainState::Active->value]
        );

        return $n > 0;
    }

    public function advanceChain(string $chainId, int $fromStep, int $toStep): bool
    {
        $now = Dates::toDatabase($this->clock->now());
        $n = $this->connection->execute(
            'UPDATE ' . $this->chains() . '
             SET `current_step` = ?, `state` = ?, `started_at` = COALESCE(`started_at`, ?)
             WHERE `chain_id` = ? AND `current_step` = ? AND `state` IN (?, ?)',
            [$toStep, ChainState::Active->value, $now, $chainId, $fromStep, ChainState::Pending->value, ChainState::Active->value]
        );

        return $n === 1;
    }

    public function completeChain(string $chainId): bool
    {
        $now = Dates::toDatabase($this->clock->now());
        $n = $this->connection->execute(
            'UPDATE ' . $this->chains() . ' SET `state` = ?, `completed_at` = ?
             WHERE `chain_id` = ? AND `state` IN (?, ?)',
            [ChainState::Completed->value, $now, $chainId, ChainState::Pending->value, ChainState::Active->value]
        );

        return $n > 0;
    }

    public function failChain(string $chainId, int $failedStep, string $reason, ?string $jobId): bool
    {
        $now = Dates::toDatabase($this->clock->now());
        $n = $this->connection->execute(
            'UPDATE ' . $this->chains() . '
             SET `state` = ?, `failed_step` = ?, `failure_reason` = ?, `failed_job_id` = ?, `failed_at` = ?
             WHERE `chain_id` = ? AND `state` IN (?, ?)',
            [ChainState::Failed->value, $failedStep, $reason, $jobId, $now, $chainId, ChainState::Pending->value, ChainState::Active->value]
        );

        return $n > 0;
    }

    public function cancelChain(string $chainId): bool
    {
        $now = Dates::toDatabase($this->clock->now());
        $n = $this->connection->execute(
            'UPDATE ' . $this->chains() . '
             SET `state` = ?, `cancel_requested` = 1, `cancelled_at` = ?
             WHERE `chain_id` = ? AND `state` NOT IN (?, ?)',
            [ChainState::Cancelled->value, $now, $chainId, ChainState::Completed->value, ChainState::Cancelled->value]
        );

        return $n > 0;
    }

    public function reactivateChain(string $chainId): bool
    {
        $n = $this->connection->execute(
            'UPDATE ' . $this->chains() . '
             SET `state` = ?, `failed_step` = NULL, `failed_job_id` = NULL, `failure_reason` = NULL, `failed_at` = NULL
             WHERE `chain_id` = ? AND `state` = ?',
            [ChainState::Active->value, $chainId, ChainState::Failed->value]
        );

        return $n === 1;
    }

    public function listChains(int $limit = 50): array
    {
        $rows = $this->connection->select(
            'SELECT * FROM ' . $this->chains() . ' ORDER BY `created_at` DESC LIMIT ' . (int) $limit
        );

        return array_map(fn (array $row): ChainRecord => $this->hydrateChain($row), $rows);
    }

    public function incompleteChains(int $limit = 50): array
    {
        $rows = $this->connection->select(
            'SELECT * FROM ' . $this->chains() . ' WHERE `state` IN (?, ?) ORDER BY `created_at` ASC LIMIT ' . (int) $limit,
            [ChainState::Pending->value, ChainState::Active->value]
        );

        return array_map(fn (array $row): ChainRecord => $this->hydrateChain($row), $rows);
    }

    public function pruneChains(\DateTimeImmutable $completedBefore, \DateTimeImmutable $failedBefore, int $limit): int
    {
        $ids = [];
        $rows = $this->connection->select(
            'SELECT `chain_id` FROM ' . $this->chains() . '
             WHERE (`state` IN (?, ?) AND COALESCE(`completed_at`, `cancelled_at`, `created_at`) <= ?)
                OR (`state` = ? AND COALESCE(`failed_at`, `created_at`) <= ?)
             LIMIT ' . (int) $limit,
            [
                ChainState::Completed->value,
                ChainState::Cancelled->value,
                Dates::toDatabase($completedBefore),
                ChainState::Failed->value,
                Dates::toDatabase($failedBefore),
            ]
        );
        foreach ($rows as $row) {
            $ids[] = (string) $row['chain_id'];
        }
        if ($ids === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $this->connection->execute('DELETE FROM ' . $this->chainStepsTable() . ' WHERE `chain_id` IN (' . $in . ')', $ids);

        return $this->connection->execute('DELETE FROM ' . $this->chains() . ' WHERE `chain_id` IN (' . $in . ')', $ids);
    }

    public function createHeader(BatchRecord $batch): void
    {
        $this->insertBatch($batch);
    }

    public function saveMembers(array $members): void
    {
        foreach ($members as $member) {
            $this->connection->execute(
                'INSERT INTO ' . $this->members() . ' (
                    batch_id, member_index, job_type, job_schema_version, payload, queue, priority,
                    max_attempts, timeout_seconds, unique_key, job_id, status, metadata, tags
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    job_id = IF(`status` = ?, VALUES(job_id), job_id),
                    status = IF(`status` = ?, VALUES(status), status)',
                [
                    $member->batchId,
                    $member->memberIndex,
                    $member->jobType,
                    $member->schemaVersion,
                    $this->encode($member->payload),
                    $member->queue,
                    $member->priority,
                    $member->maxAttempts,
                    $member->timeoutSeconds,
                    $member->uniqueKey,
                    $member->jobId,
                    $member->status->value,
                    $this->encode($member->metadata),
                    $this->encode($member->tags),
                    MemberStatus::Waiting->value,
                    MemberStatus::Waiting->value,
                ]
            );
        }
    }

    public function getBatch(string $batchId): ?BatchRecord
    {
        $row = $this->connection->selectOne('SELECT * FROM ' . $this->batches() . ' WHERE `batch_id` = ?', [$batchId]);

        return $row === null ? null : $this->hydrateBatch($row);
    }

    public function getMember(string $batchId, int $index): ?BatchMemberRecord
    {
        $row = $this->connection->selectOne(
            'SELECT * FROM ' . $this->members() . ' WHERE `batch_id` = ? AND `member_index` = ?',
            [$batchId, $index]
        );

        return $row === null ? null : $this->hydrateMember($row);
    }

    public function waitingMembers(string $batchId, int $limit = 100): array
    {
        $rows = $this->connection->select(
            'SELECT * FROM ' . $this->members() . ' WHERE `batch_id` = ? AND `status` = ? ORDER BY `member_index` ASC LIMIT ' . (int) $limit,
            [$batchId, MemberStatus::Waiting->value]
        );

        return array_map(fn (array $row): BatchMemberRecord => $this->hydrateMember($row), $rows);
    }

    public function dispatchedMembers(string $batchId, int $limit = 100): array
    {
        $rows = $this->connection->select(
            'SELECT * FROM ' . $this->members() . ' WHERE `batch_id` = ? AND `status` = ? ORDER BY `member_index` ASC LIMIT ' . (int) $limit,
            [$batchId, MemberStatus::Dispatched->value]
        );

        return array_map(fn (array $row): BatchMemberRecord => $this->hydrateMember($row), $rows);
    }

    public function markMemberDispatched(string $batchId, int $index, string $jobId): void
    {
        $this->connection->execute(
            'UPDATE ' . $this->members() . ' SET `status` = ?, `job_id` = ? WHERE `batch_id` = ? AND `member_index` = ?',
            [MemberStatus::Dispatched->value, $jobId, $batchId, $index]
        );
    }

    public function applyMemberTerminal(string $batchId, int $index, MemberStatus $status): BatchProgress
    {
        $this->connection->begin();
        try {
            $memberRow = $this->connection->selectOne(
                'SELECT * FROM ' . $this->members() . ' WHERE `batch_id` = ? AND `member_index` = ? FOR UPDATE',
                [$batchId, $index]
            );
            $batchRow = $this->connection->selectOne(
                'SELECT * FROM ' . $this->batches() . ' WHERE `batch_id` = ? FOR UPDATE',
                [$batchId]
            );
            if ($memberRow === null || $batchRow === null) {
                $this->connection->rollBack();
                $batch = $this->getBatch($batchId);
                if ($batch === null) {
                    throw new \Fuzeo\Queue\Exceptions\QueueException('Unknown batch ' . $batchId);
                }

                return new BatchProgress($batch, false);
            }
            $previous = MemberStatus::from((string) $memberRow['status']);
            $batch = $this->hydrateBatch($batchRow);
            $completed = $batch->completedJobs;
            $failed = $batch->failedJobs;
            $cancelled = $batch->cancelledJobs;
            if ($previous === $status && in_array($previous, [MemberStatus::Completed, MemberStatus::Dead, MemberStatus::Cancelled, MemberStatus::UniqueConflict], true)) {
                $this->connection->commit();

                return new BatchProgress($batch, false);
            }
            if ($previous === MemberStatus::Completed) {
                $completed--;
            }
            if ($previous === MemberStatus::Dead || $previous === MemberStatus::UniqueConflict) {
                $failed--;
            }
            if ($previous === MemberStatus::Cancelled) {
                $cancelled--;
            }
            $this->connection->execute(
                'UPDATE ' . $this->members() . ' SET `status` = ? WHERE `batch_id` = ? AND `member_index` = ?',
                [$status->value, $batchId, $index]
            );
            if ($status === MemberStatus::Completed) {
                $completed++;
            }
            if ($status === MemberStatus::Dead || $status === MemberStatus::UniqueConflict) {
                $failed++;
            }
            if ($status === MemberStatus::Cancelled) {
                $cancelled++;
            }
            $now = $this->clock->now();
            $state = $batch->state;
            $just = false;
            $completedAt = $batch->completedAt;
            $failedAt = $batch->failedAt;
            $cancelledAt = $batch->cancelledAt;
            $terminal = ($completed + $failed + $cancelled) >= $batch->totalJobs && $batch->totalJobs > 0;
            if ($terminal && ($state === BatchState::Active || $state === BatchState::Creating || $state === BatchState::Failed)) {
                if ($batch->cancelRequested) {
                    $state = BatchState::Cancelled;
                    $cancelledAt = $cancelledAt ?? $now;
                } elseif ($failed > 0) {
                    $state = BatchState::Failed;
                    $failedAt = $failedAt ?? $now;
                } else {
                    $state = BatchState::Completed;
                    $completedAt = $completedAt ?? $now;
                }
                $just = $batch->state !== $state;
            } elseif ($status === MemberStatus::Dead && $batch->failurePolicy === BatchFailurePolicy::FailFast && $state === BatchState::Active) {
                $state = BatchState::Failed;
                $failedAt = $now;
            }
            $this->connection->execute(
                'UPDATE ' . $this->batches() . '
                 SET `completed_jobs` = ?, `failed_jobs` = ?, `cancelled_jobs` = ?, `state` = ?,
                     `completed_at` = ?, `failed_at` = ?, `cancelled_at` = ?
                 WHERE `batch_id` = ?',
                [
                    max(0, $completed),
                    max(0, $failed),
                    max(0, $cancelled),
                    $state->value,
                    $completedAt !== null ? Dates::toDatabase($completedAt) : null,
                    $failedAt !== null ? Dates::toDatabase($failedAt) : null,
                    $cancelledAt !== null ? Dates::toDatabase($cancelledAt) : null,
                    $batchId,
                ]
            );
            $this->connection->commit();
            $updated = $this->getBatch($batchId) ?? $batch;

            return new BatchProgress($updated, $just);
        } catch (\Throwable $exception) {
            $this->connection->rollBack();
            throw $exception;
        }
    }

    public function activateBatch(string $batchId): bool
    {
        $waiting = $this->connection->selectOne(
            'SELECT `member_index` FROM ' . $this->members() . ' WHERE `batch_id` = ? AND `status` = ? LIMIT 1',
            [$batchId, MemberStatus::Waiting->value]
        );
        if ($waiting !== null) {
            return false;
        }
        $now = Dates::toDatabase($this->clock->now());
        $n = $this->connection->execute(
            'UPDATE ' . $this->batches() . ' SET `state` = ?, `started_at` = COALESCE(`started_at`, ?) WHERE `batch_id` = ? AND `state` = ?',
            [BatchState::Active->value, $now, $batchId, BatchState::Creating->value]
        );

        return $n === 1;
    }

    public function cancelBatch(string $batchId): bool
    {
        $now = Dates::toDatabase($this->clock->now());
        $n = $this->connection->execute(
            'UPDATE ' . $this->batches() . '
             SET `cancel_requested` = 1,
                 `state` = IF(`state` = ?, ?, `state`),
                 `cancelled_at` = IF(`state` = ?, ?, `cancelled_at`)
             WHERE `batch_id` = ? AND `state` <> ?',
            [
                BatchState::Creating->value,
                BatchState::Cancelled->value,
                BatchState::Creating->value,
                $now,
                $batchId,
                BatchState::Completed->value,
            ]
        );

        return $n > 0;
    }

    public function markFollowUpDispatched(string $batchId, string $kind, string $jobId): bool
    {
        $column = $kind === 'then' ? 'then_job_id' : 'catch_job_id';
        $n = $this->connection->execute(
            'UPDATE ' . $this->batches() . ' SET `' . $column . '` = ? WHERE `batch_id` = ? AND (`' . $column . '` IS NULL OR `' . $column . '` = \'\')',
            [$jobId, $batchId]
        );

        return $n === 1;
    }

    public function recomputeBatch(string $batchId): ?BatchRecord
    {
        $counts = $this->connection->select(
            'SELECT `status`, COUNT(*) AS n FROM ' . $this->members() . ' WHERE `batch_id` = ? GROUP BY `status`',
            [$batchId]
        );
        $completed = 0;
        $failed = 0;
        $cancelled = 0;
        foreach ($counts as $row) {
            $status = (string) $row['status'];
            $n = (int) $row['n'];
            if ($status === MemberStatus::Completed->value) {
                $completed = $n;
            }
            if ($status === MemberStatus::Dead->value || $status === MemberStatus::UniqueConflict->value) {
                $failed += $n;
            }
            if ($status === MemberStatus::Cancelled->value) {
                $cancelled = $n;
            }
        }
        $batch = $this->getBatch($batchId);
        if ($batch === null) {
            return null;
        }
        $state = $batch->state;
        $now = $this->clock->now();
        $completedAt = $batch->completedAt;
        $failedAt = $batch->failedAt;
        $cancelledAt = $batch->cancelledAt;
        if ($state !== BatchState::Creating && ($completed + $failed + $cancelled) >= $batch->totalJobs && $batch->totalJobs > 0) {
            if ($batch->cancelRequested) {
                $state = BatchState::Cancelled;
                $cancelledAt = $cancelledAt ?? $now;
            } elseif ($failed > 0) {
                $state = BatchState::Failed;
                $failedAt = $failedAt ?? $now;
            } else {
                $state = BatchState::Completed;
                $completedAt = $completedAt ?? $now;
            }
        }
        $this->connection->execute(
            'UPDATE ' . $this->batches() . '
             SET `completed_jobs` = ?, `failed_jobs` = ?, `cancelled_jobs` = ?, `state` = ?,
                 `completed_at` = ?, `failed_at` = ?, `cancelled_at` = ?
             WHERE `batch_id` = ?',
            [
                $completed,
                $failed,
                $cancelled,
                $state->value,
                $completedAt !== null ? Dates::toDatabase($completedAt) : null,
                $failedAt !== null ? Dates::toDatabase($failedAt) : null,
                $cancelledAt !== null ? Dates::toDatabase($cancelledAt) : null,
                $batchId,
            ]
        );

        return $this->getBatch($batchId);
    }

    public function listBatches(int $limit = 50): array
    {
        $rows = $this->connection->select(
            'SELECT * FROM ' . $this->batches() . ' ORDER BY `created_at` DESC LIMIT ' . (int) $limit
        );

        return array_map(fn (array $row): BatchRecord => $this->hydrateBatch($row), $rows);
    }

    public function incompleteBatches(int $limit = 50): array
    {
        $rows = $this->connection->select(
            'SELECT * FROM ' . $this->batches() . ' WHERE `state` IN (?, ?) ORDER BY `created_at` ASC LIMIT ' . (int) $limit,
            [BatchState::Active->value, BatchState::Creating->value]
        );

        return array_map(fn (array $row): BatchRecord => $this->hydrateBatch($row), $rows);
    }

    public function creatingBatches(int $limit = 50): array
    {
        $rows = $this->connection->select(
            'SELECT * FROM ' . $this->batches() . ' WHERE `state` = ? ORDER BY `created_at` ASC LIMIT ' . (int) $limit,
            [BatchState::Creating->value]
        );

        return array_map(fn (array $row): BatchRecord => $this->hydrateBatch($row), $rows);
    }

    public function pruneBatches(\DateTimeImmutable $completedBefore, \DateTimeImmutable $failedBefore, int $limit): int
    {
        $rows = $this->connection->select(
            'SELECT `batch_id` FROM ' . $this->batches() . '
             WHERE (`state` IN (?, ?) AND COALESCE(`completed_at`, `cancelled_at`, `created_at`) <= ?)
                OR (`state` = ? AND COALESCE(`failed_at`, `created_at`) <= ?)
             LIMIT ' . (int) $limit,
            [
                BatchState::Completed->value,
                BatchState::Cancelled->value,
                Dates::toDatabase($completedBefore),
                BatchState::Failed->value,
                Dates::toDatabase($failedBefore),
            ]
        );
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (string) $row['batch_id'];
        }
        if ($ids === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $this->connection->execute('DELETE FROM ' . $this->members() . ' WHERE `batch_id` IN (' . $in . ')', $ids);

        return $this->connection->execute('DELETE FROM ' . $this->batches() . ' WHERE `batch_id` IN (' . $in . ')', $ids);
    }

    private function insertChain(ChainRecord $chain): void
    {
        $this->connection->execute(
            'INSERT INTO ' . $this->chains() . ' (
                chain_id, origin_package, origin_version, network_id, site_id, scope, state, current_step, total_steps,
                failure_policy, failed_step, failed_job_id, failure_reason, cancel_requested, created_at, started_at,
                completed_at, cancelled_at, failed_at, metadata
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $chain->chainId,
                $chain->origin->package,
                $chain->origin->version,
                $chain->context->networkId,
                $chain->context->siteId,
                $chain->context->scope->value,
                $chain->state->value,
                $chain->currentStep,
                $chain->totalSteps,
                $chain->failurePolicy->value,
                $chain->failedStep,
                $chain->failedJobId,
                $chain->failureReason,
                $chain->cancelRequested ? 1 : 0,
                Dates::toDatabase($chain->createdAt),
                $chain->startedAt !== null ? Dates::toDatabase($chain->startedAt) : null,
                $chain->completedAt !== null ? Dates::toDatabase($chain->completedAt) : null,
                $chain->cancelledAt !== null ? Dates::toDatabase($chain->cancelledAt) : null,
                $chain->failedAt !== null ? Dates::toDatabase($chain->failedAt) : null,
                $this->encode($chain->metadata),
            ]
        );
    }

    private function insertStep(ChainStepRecord $step): void
    {
        $this->connection->execute(
            'INSERT INTO ' . $this->chainStepsTable() . ' (
                chain_id, step_number, job_type, job_schema_version, payload, queue, priority, max_attempts,
                timeout_seconds, unique_key, job_id, status, parent_job_id, metadata, tags
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $step->chainId,
                $step->stepNumber,
                $step->jobType,
                $step->schemaVersion,
                $this->encode($step->payload),
                $step->queue,
                $step->priority,
                $step->maxAttempts,
                $step->timeoutSeconds,
                $step->uniqueKey,
                $step->jobId,
                $step->status->value,
                $step->parentJobId,
                $this->encode($step->metadata),
                $this->encode($step->tags),
            ]
        );
    }

    private function insertBatch(BatchRecord $batch): void
    {
        $this->connection->execute(
            'INSERT INTO ' . $this->batches() . ' (
                batch_id, name, origin_package, origin_version, network_id, site_id, scope, state, total_jobs,
                completed_jobs, failed_jobs, cancelled_jobs, failure_policy, cancel_requested, then_job_type, then_payload,
                then_queue, catch_job_type, catch_payload, catch_queue, then_job_id, catch_job_id, created_at, started_at,
                completed_at, cancelled_at, failed_at, metadata
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $batch->batchId,
                $batch->name,
                $batch->origin->package,
                $batch->origin->version,
                $batch->context->networkId,
                $batch->context->siteId,
                $batch->context->scope->value,
                $batch->state->value,
                $batch->totalJobs,
                $batch->completedJobs,
                $batch->failedJobs,
                $batch->cancelledJobs,
                $batch->failurePolicy->value,
                $batch->cancelRequested ? 1 : 0,
                $batch->thenJobType,
                $batch->thenPayload !== null ? $this->encode($batch->thenPayload) : null,
                $batch->thenQueue,
                $batch->catchJobType,
                $batch->catchPayload !== null ? $this->encode($batch->catchPayload) : null,
                $batch->catchQueue,
                $batch->thenJobId,
                $batch->catchJobId,
                Dates::toDatabase($batch->createdAt),
                $batch->startedAt !== null ? Dates::toDatabase($batch->startedAt) : null,
                $batch->completedAt !== null ? Dates::toDatabase($batch->completedAt) : null,
                $batch->cancelledAt !== null ? Dates::toDatabase($batch->cancelledAt) : null,
                $batch->failedAt !== null ? Dates::toDatabase($batch->failedAt) : null,
                $this->encode($batch->metadata),
            ]
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateChain(array $row): ChainRecord
    {
        $metadata = $this->decodeMap($row['metadata'] ?? null);

        return new ChainRecord(
            (string) $row['chain_id'],
            new Origin((string) $row['origin_package'], (string) $row['origin_version']),
            ExecutionContext::fromArray([
                'network_id' => (int) $row['network_id'],
                'site_id' => (int) $row['site_id'],
                'scope' => (string) $row['scope'],
            ]),
            ChainState::from((string) $row['state']),
            (int) $row['current_step'],
            (int) $row['total_steps'],
            ChainFailurePolicy::from((string) $row['failure_policy']),
            Dates::fromDatabase((string) $row['created_at']),
            isset($row['started_at']) && is_string($row['started_at']) ? Dates::fromDatabase($row['started_at']) : null,
            isset($row['completed_at']) && is_string($row['completed_at']) ? Dates::fromDatabase($row['completed_at']) : null,
            isset($row['cancelled_at']) && is_string($row['cancelled_at']) ? Dates::fromDatabase($row['cancelled_at']) : null,
            isset($row['failed_at']) && is_string($row['failed_at']) ? Dates::fromDatabase($row['failed_at']) : null,
            (int) ($row['cancel_requested'] ?? 0) === 1,
            isset($row['failed_step']) && $row['failed_step'] !== null ? (int) $row['failed_step'] : null,
            isset($row['failed_job_id']) && is_string($row['failed_job_id']) ? $row['failed_job_id'] : null,
            isset($row['failure_reason']) && is_string($row['failure_reason']) ? $row['failure_reason'] : null,
            $metadata,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateStep(array $row): ChainStepRecord
    {
        return new ChainStepRecord(
            (string) $row['chain_id'],
            (int) $row['step_number'],
            (string) $row['job_type'],
            (int) $row['job_schema_version'],
            $this->decodeMap($row['payload'] ?? null),
            (string) $row['queue'],
            (int) $row['priority'],
            (int) $row['max_attempts'],
            (int) $row['timeout_seconds'],
            MemberStatus::from((string) $row['status']),
            $this->decodeMap($row['metadata'] ?? null),
            $this->decodeList($row['tags'] ?? null),
            isset($row['unique_key']) && is_string($row['unique_key']) ? $row['unique_key'] : null,
            isset($row['job_id']) && is_string($row['job_id']) ? $row['job_id'] : null,
            isset($row['parent_job_id']) && is_string($row['parent_job_id']) ? $row['parent_job_id'] : null,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateBatch(array $row): BatchRecord
    {
        $thenPayload = isset($row['then_payload']) && is_string($row['then_payload']) && $row['then_payload'] !== ''
            ? $this->decodeMap($row['then_payload'])
            : null;
        $catchPayload = isset($row['catch_payload']) && is_string($row['catch_payload']) && $row['catch_payload'] !== ''
            ? $this->decodeMap($row['catch_payload'])
            : null;

        return new BatchRecord(
            (string) $row['batch_id'],
            new Origin((string) $row['origin_package'], (string) $row['origin_version']),
            ExecutionContext::fromArray([
                'network_id' => (int) $row['network_id'],
                'site_id' => (int) $row['site_id'],
                'scope' => (string) $row['scope'],
            ]),
            BatchState::from((string) $row['state']),
            (int) $row['total_jobs'],
            (int) $row['completed_jobs'],
            (int) $row['failed_jobs'],
            (int) $row['cancelled_jobs'],
            BatchFailurePolicy::from((string) $row['failure_policy']),
            Dates::fromDatabase((string) $row['created_at']),
            isset($row['name']) && is_string($row['name']) && $row['name'] !== '' ? $row['name'] : null,
            isset($row['started_at']) && is_string($row['started_at']) ? Dates::fromDatabase($row['started_at']) : null,
            isset($row['completed_at']) && is_string($row['completed_at']) ? Dates::fromDatabase($row['completed_at']) : null,
            isset($row['cancelled_at']) && is_string($row['cancelled_at']) ? Dates::fromDatabase($row['cancelled_at']) : null,
            isset($row['failed_at']) && is_string($row['failed_at']) ? Dates::fromDatabase($row['failed_at']) : null,
            (int) ($row['cancel_requested'] ?? 0) === 1,
            isset($row['then_job_type']) && is_string($row['then_job_type']) && $row['then_job_type'] !== '' ? $row['then_job_type'] : null,
            $thenPayload,
            isset($row['then_queue']) && is_string($row['then_queue']) && $row['then_queue'] !== '' ? $row['then_queue'] : null,
            isset($row['catch_job_type']) && is_string($row['catch_job_type']) && $row['catch_job_type'] !== '' ? $row['catch_job_type'] : null,
            $catchPayload,
            isset($row['catch_queue']) && is_string($row['catch_queue']) && $row['catch_queue'] !== '' ? $row['catch_queue'] : null,
            isset($row['then_job_id']) && is_string($row['then_job_id']) && $row['then_job_id'] !== '' ? $row['then_job_id'] : null,
            isset($row['catch_job_id']) && is_string($row['catch_job_id']) && $row['catch_job_id'] !== '' ? $row['catch_job_id'] : null,
            $this->decodeMap($row['metadata'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateMember(array $row): BatchMemberRecord
    {
        return new BatchMemberRecord(
            (string) $row['batch_id'],
            (int) $row['member_index'],
            (string) $row['job_type'],
            (int) $row['job_schema_version'],
            $this->decodeMap($row['payload'] ?? null),
            (string) $row['queue'],
            (int) $row['priority'],
            (int) $row['max_attempts'],
            (int) $row['timeout_seconds'],
            MemberStatus::from((string) $row['status']),
            $this->decodeMap($row['metadata'] ?? null),
            $this->decodeList($row['tags'] ?? null),
            isset($row['unique_key']) && is_string($row['unique_key']) ? $row['unique_key'] : null,
            isset($row['job_id']) && is_string($row['job_id']) ? $row['job_id'] : null,
        );
    }

    /**
     * @param array<string, mixed>|list<mixed> $data
     */
    private function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMap(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return list<string>
     */
    private function decodeList(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $item) {
            if (is_string($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    private function chains(): string
    {
        return Schema::quoteTable($this->connection->prefix(), Schema::CHAINS);
    }

    private function chainStepsTable(): string
    {
        return Schema::quoteTable($this->connection->prefix(), Schema::CHAIN_STEPS);
    }

    private function batches(): string
    {
        return Schema::quoteTable($this->connection->prefix(), Schema::BATCHES);
    }

    private function members(): string
    {
        return Schema::quoteTable($this->connection->prefix(), Schema::BATCH_MEMBERS);
    }
}
