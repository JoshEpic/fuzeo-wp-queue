<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Orchestration;

/**
 * O(1) batch counter + terminal-state transition. Drivers must persist only the
 * updated member and header; they must not scan every member on the hot path.
 *
 * @internal
 */
final class BatchCounters
{
    public static function apply(
        BatchRecord $batch,
        MemberStatus $previous,
        MemberStatus $next,
        \DateTimeImmutable $now,
    ): BatchProgress {
        $terminal = [
            MemberStatus::Completed,
            MemberStatus::Dead,
            MemberStatus::Cancelled,
            MemberStatus::UniqueConflict,
        ];
        if (in_array($previous, $terminal, true) && $previous === $next) {
            return new BatchProgress($batch, false);
        }

        $completed = $batch->completedJobs;
        $failed = $batch->failedJobs;
        $cancelled = $batch->cancelledJobs;
        if ($previous === MemberStatus::Completed) {
            $completed--;
        }
        if ($previous === MemberStatus::Dead || $previous === MemberStatus::UniqueConflict) {
            $failed--;
        }
        if ($previous === MemberStatus::Cancelled) {
            $cancelled--;
        }
        if ($next === MemberStatus::Completed) {
            $completed++;
        }
        if ($next === MemberStatus::Dead || $next === MemberStatus::UniqueConflict) {
            $failed++;
        }
        if ($next === MemberStatus::Cancelled) {
            $cancelled++;
        }
        $completed = max(0, $completed);
        $failed = max(0, $failed);
        $cancelled = max(0, $cancelled);

        $state = $batch->state;
        $just = false;
        $completedAt = $batch->completedAt;
        $failedAt = $batch->failedAt;
        $cancelledAt = $batch->cancelledAt;
        $allDone = ($completed + $failed + $cancelled) >= $batch->totalJobs && $batch->totalJobs > 0;
        if ($allDone && ($state === BatchState::Active || $state === BatchState::Creating || $state === BatchState::Failed)) {
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
        } elseif ($next === MemberStatus::Dead && $batch->failurePolicy === BatchFailurePolicy::FailFast && $state === BatchState::Active) {
            $state = BatchState::Failed;
            $failedAt = $now;
            $just = false;
        }

        $updated = new BatchRecord(
            $batch->batchId,
            $batch->origin,
            $batch->context,
            $state,
            $batch->totalJobs,
            $completed,
            $failed,
            $cancelled,
            $batch->failurePolicy,
            $batch->createdAt,
            $batch->name,
            $batch->startedAt,
            $completedAt,
            $cancelledAt,
            $failedAt,
            $batch->cancelRequested,
            $batch->thenJobType,
            $batch->thenPayload,
            $batch->thenQueue,
            $batch->catchJobType,
            $batch->catchPayload,
            $batch->catchQueue,
            $batch->thenJobId,
            $batch->catchJobId,
            $batch->metadata,
        );

        return new BatchProgress($updated, $just);
    }
}
