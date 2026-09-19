<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Orchestration;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Support\SystemClock;

final class MemoryOrchestrationStore implements OrchestrationStore
{
    /** @var array<string, ChainRecord> */
    private array $chains = [];

    /** @var array<string, array<int, ChainStepRecord>> */
    private array $steps = [];

    /** @var array<string, BatchRecord> */
    private array $batches = [];

    /** @var array<string, array<int, BatchMemberRecord>> */
    private array $members = [];

    public function __construct(private readonly Clock $clock = new SystemClock())
    {
    }

    public function createChain(ChainRecord $chain, array $steps): void
    {
        $this->chains[$chain->chainId] = $chain;
        foreach ($steps as $step) {
            $this->steps[$chain->chainId][$step->stepNumber] = $step;
        }
    }

    public function getChain(string $chainId): ?ChainRecord
    {
        return $this->chains[$chainId] ?? null;
    }

    public function getStep(string $chainId, int $stepNumber): ?ChainStepRecord
    {
        return $this->steps[$chainId][$stepNumber] ?? null;
    }

    public function steps(string $chainId): array
    {
        $rows = $this->steps[$chainId] ?? [];
        ksort($rows);

        return array_values($rows);
    }

    public function markStepDispatched(string $chainId, int $stepNumber, string $jobId): void
    {
        $step = $this->steps[$chainId][$stepNumber] ?? null;
        if ($step === null) {
            return;
        }
        $this->steps[$chainId][$stepNumber] = $this->copyStep($step, MemberStatus::Dispatched, $jobId);
    }

    public function markStepStatus(string $chainId, int $stepNumber, MemberStatus $status): void
    {
        $step = $this->steps[$chainId][$stepNumber] ?? null;
        if ($step === null) {
            return;
        }
        $this->steps[$chainId][$stepNumber] = $this->copyStep($step, $status, $step->jobId);
    }

    public function activateChain(string $chainId): bool
    {
        $chain = $this->chains[$chainId] ?? null;
        if ($chain === null || ($chain->state !== ChainState::Pending && $chain->state !== ChainState::Active)) {
            return false;
        }
        $this->chains[$chainId] = $this->copyChain($chain, [
            'state' => ChainState::Active,
            'startedAt' => $chain->startedAt ?? $this->clock->now(),
        ]);

        return true;
    }

    public function advanceChain(string $chainId, int $fromStep, int $toStep): bool
    {
        $chain = $this->chains[$chainId] ?? null;
        if ($chain === null || $chain->currentStep !== $fromStep) {
            return false;
        }
        if ($chain->state !== ChainState::Active && $chain->state !== ChainState::Pending) {
            return false;
        }
        $this->chains[$chainId] = $this->copyChain($chain, [
            'state' => ChainState::Active,
            'currentStep' => $toStep,
            'startedAt' => $chain->startedAt ?? $this->clock->now(),
        ]);

        return true;
    }

    public function completeChain(string $chainId): bool
    {
        $chain = $this->chains[$chainId] ?? null;
        if ($chain === null) {
            return false;
        }
        if ($chain->state === ChainState::Completed) {
            return true;
        }
        if ($chain->state !== ChainState::Active && $chain->state !== ChainState::Pending) {
            return false;
        }
        $this->chains[$chainId] = $this->copyChain($chain, [
            'state' => ChainState::Completed,
            'completedAt' => $this->clock->now(),
        ]);

        return true;
    }

    public function failChain(string $chainId, int $failedStep, string $reason, ?string $jobId): bool
    {
        $chain = $this->chains[$chainId] ?? null;
        if ($chain === null) {
            return false;
        }
        if ($chain->state === ChainState::Failed) {
            return true;
        }
        if ($chain->state === ChainState::Completed || $chain->state === ChainState::Cancelled) {
            return false;
        }
        $this->chains[$chainId] = $this->copyChain($chain, [
            'state' => ChainState::Failed,
            'failedStep' => $failedStep,
            'failedJobId' => $jobId,
            'failureReason' => $reason,
            'failedAt' => $this->clock->now(),
        ]);

        return true;
    }

    public function cancelChain(string $chainId): bool
    {
        $chain = $this->chains[$chainId] ?? null;
        if ($chain === null) {
            return false;
        }
        if ($chain->state === ChainState::Completed || $chain->state === ChainState::Cancelled) {
            return false;
        }
        $this->chains[$chainId] = $this->copyChain($chain, [
            'state' => ChainState::Cancelled,
            'cancelRequested' => true,
            'cancelledAt' => $this->clock->now(),
        ]);

        return true;
    }

    public function reactivateChain(string $chainId): bool
    {
        $chain = $this->chains[$chainId] ?? null;
        if ($chain === null || $chain->state !== ChainState::Failed) {
            return false;
        }
        $this->chains[$chainId] = $this->copyChain($chain, [
            'state' => ChainState::Active,
            'failedStep' => null,
            'failedJobId' => null,
            'failureReason' => null,
            'failedAt' => null,
        ]);

        return true;
    }

    public function listChains(int $limit = 50): array
    {
        return array_slice(array_values($this->chains), 0, $limit);
    }

    public function incompleteChains(int $limit = 50): array
    {
        $out = [];
        foreach ($this->chains as $chain) {
            if ($chain->state === ChainState::Pending || $chain->state === ChainState::Active) {
                $out[] = $chain;
            }
        }

        return array_slice($out, 0, $limit);
    }

    public function pruneChains(\DateTimeImmutable $completedBefore, \DateTimeImmutable $failedBefore, int $limit): int
    {
        $deleted = 0;
        foreach ($this->chains as $id => $chain) {
            if ($deleted >= $limit) {
                break;
            }
            if ($chain->state === ChainState::Completed || $chain->state === ChainState::Cancelled) {
                $cut = $chain->completedAt ?? $chain->cancelledAt ?? $chain->createdAt;
                if ($cut > $completedBefore) {
                    continue;
                }
            } elseif ($chain->state === ChainState::Failed) {
                $cut = $chain->failedAt ?? $chain->createdAt;
                if ($cut > $failedBefore) {
                    continue;
                }
            } else {
                continue;
            }
            unset($this->chains[$id], $this->steps[$id]);
            $deleted++;
        }

        return $deleted;
    }

    public function createHeader(BatchRecord $batch): void
    {
        $this->batches[$batch->batchId] = $batch;
        $this->members[$batch->batchId] ??= [];
    }

    public function saveMembers(array $members): void
    {
        foreach ($members as $member) {
            $existing = $this->members[$member->batchId][$member->memberIndex] ?? null;
            if ($existing !== null && $existing->status !== MemberStatus::Waiting) {
                continue;
            }
            $this->members[$member->batchId][$member->memberIndex] = $member;
        }
    }

    public function getBatch(string $batchId): ?BatchRecord
    {
        return $this->batches[$batchId] ?? null;
    }

    public function getMember(string $batchId, int $index): ?BatchMemberRecord
    {
        return $this->members[$batchId][$index] ?? null;
    }

    public function waitingMembers(string $batchId, int $limit = 100): array
    {
        $out = [];
        foreach ($this->members[$batchId] ?? [] as $member) {
            if ($member->status === MemberStatus::Waiting) {
                $out[] = $member;
                if (count($out) >= $limit) {
                    break;
                }
            }
        }

        return $out;
    }

    public function dispatchedMembers(string $batchId, int $limit = 100): array
    {
        $out = [];
        foreach ($this->members[$batchId] ?? [] as $member) {
            if ($member->status === MemberStatus::Dispatched && $member->jobId !== null) {
                $out[] = $member;
                if (count($out) >= $limit) {
                    break;
                }
            }
        }

        return $out;
    }

    public function markMemberDispatched(string $batchId, int $index, string $jobId): void
    {
        $member = $this->members[$batchId][$index] ?? null;
        if ($member === null) {
            return;
        }
        $this->members[$batchId][$index] = $this->copyMember($member, MemberStatus::Dispatched, $jobId);
    }

    public function applyMemberTerminal(string $batchId, int $index, MemberStatus $status): BatchProgress
    {
        $batch = $this->batches[$batchId] ?? null;
        $member = $this->members[$batchId][$index] ?? null;
        if ($batch === null || $member === null) {
            return new BatchProgress($batch ?? new BatchRecord(
                $batchId,
                new \Fuzeo\Queue\Jobs\Origin('fuzeowp/queue', '0'),
                \Fuzeo\Queue\Jobs\ExecutionContext::site(1, 1),
                BatchState::Failed,
                0,
                0,
                0,
                0,
                BatchFailurePolicy::CollectAll,
                $this->clock->now(),
            ), false);
        }
        if (in_array($member->status, [MemberStatus::Completed, MemberStatus::Dead, MemberStatus::Cancelled, MemberStatus::UniqueConflict], true)) {
            if ($member->status === $status) {
                return new BatchProgress($batch, false);
            }
        }
        $completed = $batch->completedJobs;
        $failed = $batch->failedJobs;
        $cancelled = $batch->cancelledJobs;
        if ($member->status === MemberStatus::Completed) {
            $completed--;
        }
        if ($member->status === MemberStatus::Dead || $member->status === MemberStatus::UniqueConflict) {
            $failed--;
        }
        if ($member->status === MemberStatus::Cancelled) {
            $cancelled--;
        }
        $this->members[$batchId][$index] = $this->copyMember($member, $status, $member->jobId);
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
            $just = false;
        }
        $updated = $this->copyBatch($batch, [
            'state' => $state,
            'completedJobs' => max(0, $completed),
            'failedJobs' => max(0, $failed),
            'cancelledJobs' => max(0, $cancelled),
            'completedAt' => $completedAt,
            'failedAt' => $failedAt,
            'cancelledAt' => $cancelledAt,
        ]);
        $this->batches[$batchId] = $updated;

        return new BatchProgress($updated, $just);
    }

    public function activateBatch(string $batchId): bool
    {
        $batch = $this->batches[$batchId] ?? null;
        if ($batch === null || $batch->state !== BatchState::Creating) {
            return false;
        }
        foreach ($this->members[$batchId] ?? [] as $member) {
            if ($member->status === MemberStatus::Waiting) {
                return false;
            }
        }
        $this->batches[$batchId] = $this->copyBatch($batch, [
            'state' => BatchState::Active,
            'startedAt' => $batch->startedAt ?? $this->clock->now(),
        ]);

        return true;
    }

    public function cancelBatch(string $batchId): bool
    {
        return $this->requestCancelBatch($batchId);
    }

    public function markFollowUpDispatched(string $batchId, string $kind, string $jobId): bool
    {
        $batch = $this->batches[$batchId] ?? null;
        if ($batch === null) {
            return false;
        }
        if ($kind === 'then') {
            if ($batch->thenJobId !== null && $batch->thenJobId !== '') {
                return false;
            }
            $this->batches[$batchId] = $this->copyBatch($batch, ['thenJobId' => $jobId]);

            return true;
        }
        if ($batch->catchJobId !== null && $batch->catchJobId !== '') {
            return false;
        }
        $this->batches[$batchId] = $this->copyBatch($batch, ['catchJobId' => $jobId]);

        return true;
    }

    public function recomputeBatch(string $batchId): ?BatchRecord
    {
        $batch = $this->batches[$batchId] ?? null;
        if ($batch === null) {
            return null;
        }
        $completed = 0;
        $failed = 0;
        $cancelled = 0;
        foreach ($this->members[$batchId] ?? [] as $member) {
            if ($member->status === MemberStatus::Completed) {
                $completed++;
            } elseif ($member->status === MemberStatus::Dead || $member->status === MemberStatus::UniqueConflict) {
                $failed++;
            } elseif ($member->status === MemberStatus::Cancelled) {
                $cancelled++;
            }
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
        $updated = $this->copyBatch($batch, [
            'state' => $state,
            'completedJobs' => $completed,
            'failedJobs' => $failed,
            'cancelledJobs' => $cancelled,
            'completedAt' => $completedAt,
            'failedAt' => $failedAt,
            'cancelledAt' => $cancelledAt,
        ]);
        $this->batches[$batchId] = $updated;

        return $updated;
    }

    public function listBatches(int $limit = 50): array
    {
        return array_slice(array_values($this->batches), 0, $limit);
    }

    public function incompleteBatches(int $limit = 50): array
    {
        $out = [];
        foreach ($this->batches as $batch) {
            if ($batch->state === BatchState::Active || $batch->state === BatchState::Creating) {
                $out[] = $batch;
            }
        }

        return array_slice($out, 0, $limit);
    }

    public function creatingBatches(int $limit = 50): array
    {
        $out = [];
        foreach ($this->batches as $batch) {
            if ($batch->state === BatchState::Creating) {
                $out[] = $batch;
            }
        }

        return array_slice($out, 0, $limit);
    }

    public function pruneBatches(\DateTimeImmutable $completedBefore, \DateTimeImmutable $failedBefore, int $limit): int
    {
        $deleted = 0;
        foreach ($this->batches as $id => $batch) {
            if ($deleted >= $limit) {
                break;
            }
            if ($batch->state === BatchState::Completed || $batch->state === BatchState::Cancelled) {
                $cut = $batch->completedAt ?? $batch->cancelledAt ?? $batch->createdAt;
                if ($cut > $completedBefore) {
                    continue;
                }
            } elseif ($batch->state === BatchState::Failed) {
                $cut = $batch->failedAt ?? $batch->createdAt;
                if ($cut > $failedBefore) {
                    continue;
                }
            } else {
                continue;
            }
            unset($this->batches[$id], $this->members[$id]);
            $deleted++;
        }

        return $deleted;
    }

    private function requestCancelBatch(string $batchId): bool
    {
        $batch = $this->batches[$batchId] ?? null;
        if ($batch === null) {
            return false;
        }
        if ($batch->state === BatchState::Completed) {
            return false;
        }
        $now = $this->clock->now();
        $state = $batch->state === BatchState::Creating ? BatchState::Cancelled : $batch->state;
        if ($batch->allTerminal() || $batch->state === BatchState::Creating) {
            $state = BatchState::Cancelled;
        }
        $this->batches[$batchId] = $this->copyBatch($batch, [
            'cancelRequested' => true,
            'state' => $state === BatchState::Creating ? BatchState::Cancelled : $batch->state,
            'cancelledAt' => $state === BatchState::Cancelled ? $now : $batch->cancelledAt,
        ]);
        if ($batch->state === BatchState::Creating) {
            $this->batches[$batchId] = $this->copyBatch($this->batches[$batchId], [
                'state' => BatchState::Cancelled,
                'cancelledAt' => $now,
            ]);
        }

        return true;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function copyChain(ChainRecord $chain, array $overrides): ChainRecord
    {
        return new ChainRecord(
            $chain->chainId,
            $chain->origin,
            $chain->context,
            $overrides['state'] ?? $chain->state,
            $overrides['currentStep'] ?? $chain->currentStep,
            $chain->totalSteps,
            $chain->failurePolicy,
            $chain->createdAt,
            array_key_exists('startedAt', $overrides) ? $overrides['startedAt'] : $chain->startedAt,
            array_key_exists('completedAt', $overrides) ? $overrides['completedAt'] : $chain->completedAt,
            array_key_exists('cancelledAt', $overrides) ? $overrides['cancelledAt'] : $chain->cancelledAt,
            array_key_exists('failedAt', $overrides) ? $overrides['failedAt'] : $chain->failedAt,
            $overrides['cancelRequested'] ?? $chain->cancelRequested,
            array_key_exists('failedStep', $overrides) ? $overrides['failedStep'] : $chain->failedStep,
            array_key_exists('failedJobId', $overrides) ? $overrides['failedJobId'] : $chain->failedJobId,
            array_key_exists('failureReason', $overrides) ? $overrides['failureReason'] : $chain->failureReason,
            $chain->metadata,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function copyBatch(BatchRecord $batch, array $overrides): BatchRecord
    {
        return new BatchRecord(
            $batch->batchId,
            $batch->origin,
            $batch->context,
            $overrides['state'] ?? $batch->state,
            $batch->totalJobs,
            $overrides['completedJobs'] ?? $batch->completedJobs,
            $overrides['failedJobs'] ?? $batch->failedJobs,
            $overrides['cancelledJobs'] ?? $batch->cancelledJobs,
            $batch->failurePolicy,
            $batch->createdAt,
            $batch->name,
            array_key_exists('startedAt', $overrides) ? $overrides['startedAt'] : $batch->startedAt,
            array_key_exists('completedAt', $overrides) ? $overrides['completedAt'] : $batch->completedAt,
            array_key_exists('cancelledAt', $overrides) ? $overrides['cancelledAt'] : $batch->cancelledAt,
            array_key_exists('failedAt', $overrides) ? $overrides['failedAt'] : $batch->failedAt,
            $overrides['cancelRequested'] ?? $batch->cancelRequested,
            $batch->thenJobType,
            $batch->thenPayload,
            $batch->thenQueue,
            $batch->catchJobType,
            $batch->catchPayload,
            $batch->catchQueue,
            array_key_exists('thenJobId', $overrides) ? $overrides['thenJobId'] : $batch->thenJobId,
            array_key_exists('catchJobId', $overrides) ? $overrides['catchJobId'] : $batch->catchJobId,
            $batch->metadata,
        );
    }

    private function copyStep(ChainStepRecord $step, MemberStatus $status, ?string $jobId): ChainStepRecord
    {
        return new ChainStepRecord(
            $step->chainId,
            $step->stepNumber,
            $step->jobType,
            $step->schemaVersion,
            $step->payload,
            $step->queue,
            $step->priority,
            $step->maxAttempts,
            $step->timeoutSeconds,
            $status,
            $step->metadata,
            $step->tags,
            $step->uniqueKey,
            $jobId,
            $step->parentJobId,
        );
    }

    private function copyMember(BatchMemberRecord $member, MemberStatus $status, ?string $jobId): BatchMemberRecord
    {
        return new BatchMemberRecord(
            $member->batchId,
            $member->memberIndex,
            $member->jobType,
            $member->schemaVersion,
            $member->payload,
            $member->queue,
            $member->priority,
            $member->maxAttempts,
            $member->timeoutSeconds,
            $status,
            $member->metadata,
            $member->tags,
            $member->uniqueKey,
            $jobId,
        );
    }
}
