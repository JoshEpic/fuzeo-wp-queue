<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Orchestration;

interface BatchStore
{
    public function createHeader(BatchRecord $batch): void;

    /**
     * @param list<BatchMemberRecord> $members
     */
    public function saveMembers(array $members): void;

    public function getBatch(string $batchId): ?BatchRecord;

    public function getMember(string $batchId, int $index): ?BatchMemberRecord;

    /**
     * @return list<BatchMemberRecord>
     */
    public function waitingMembers(string $batchId, int $limit = 100): array;

    /**
     * @return list<BatchMemberRecord>
     */
    public function dispatchedMembers(string $batchId, int $limit = 100): array;

    public function markMemberDispatched(string $batchId, int $index, string $jobId): void;

    public function applyMemberTerminal(string $batchId, int $index, MemberStatus $status): BatchProgress;

    public function activateBatch(string $batchId): bool;

    public function cancelBatch(string $batchId): bool;

    public function markFollowUpDispatched(string $batchId, string $kind, string $jobId): bool;

    public function recomputeBatch(string $batchId): ?BatchRecord;

    /**
     * @return list<BatchRecord>
     */
    public function listBatches(int $limit = 50): array;

    /**
     * @return list<BatchRecord>
     */
    public function incompleteBatches(int $limit = 50): array;

    /**
     * @return list<BatchRecord>
     */
    public function creatingBatches(int $limit = 50): array;

    public function pruneBatches(\DateTimeImmutable $completedBefore, \DateTimeImmutable $failedBefore, int $limit): int;
}
