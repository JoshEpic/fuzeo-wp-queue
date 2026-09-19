<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Orchestration;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Redis\RedisClient;
use Fuzeo\Queue\Redis\RedisKeys;
use Fuzeo\Queue\Support\SystemClock;

final class RedisOrchestrationStore implements OrchestrationStore
{
    public function __construct(
        private readonly RedisClient $redis,
        private readonly RedisKeys $keys,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function createChain(ChainRecord $chain, array $steps): void
    {
        $this->saveChain($chain);
        foreach ($steps as $step) {
            $this->saveStep($step);
        }
        $this->redis->command('SADD', [$this->keys->chainsIncomplete(), $chain->chainId]);
        $this->redis->command('ZADD', [$this->keys->chainsAll(), (string) $chain->createdAt->getTimestamp(), $chain->chainId]);
    }

    public function getChain(string $chainId): ?ChainRecord
    {
        $raw = $this->redis->command('GET', [$this->keys->chain($chainId)]);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? ChainRecord::fromArray($data) : null;
    }

    public function getStep(string $chainId, int $stepNumber): ?ChainStepRecord
    {
        $raw = $this->redis->command('GET', [$this->keys->chainStep($chainId, $stepNumber)]);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $this->stepFromArray($data) : null;
    }

    public function steps(string $chainId): array
    {
        $chain = $this->getChain($chainId);
        if ($chain === null) {
            return [];
        }
        $out = [];
        for ($i = 1; $i <= $chain->totalSteps; $i++) {
            $step = $this->getStep($chainId, $i);
            if ($step !== null) {
                $out[] = $step;
            }
        }

        return $out;
    }

    public function markStepDispatched(string $chainId, int $stepNumber, string $jobId): void
    {
        $step = $this->getStep($chainId, $stepNumber);
        if ($step === null) {
            return;
        }
        $this->saveStep(new ChainStepRecord(
            $step->chainId,
            $step->stepNumber,
            $step->jobType,
            $step->schemaVersion,
            $step->payload,
            $step->queue,
            $step->priority,
            $step->maxAttempts,
            $step->timeoutSeconds,
            MemberStatus::Dispatched,
            $step->metadata,
            $step->tags,
            $step->uniqueKey,
            $jobId,
            $step->parentJobId,
        ));
    }

    public function markStepStatus(string $chainId, int $stepNumber, MemberStatus $status): void
    {
        $step = $this->getStep($chainId, $stepNumber);
        if ($step === null) {
            return;
        }
        $this->saveStep(new ChainStepRecord(
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
            $step->jobId,
            $step->parentJobId,
        ));
    }

    public function activateChain(string $chainId): bool
    {
        $chain = $this->getChain($chainId);
        if ($chain === null || ($chain->state !== ChainState::Pending && $chain->state !== ChainState::Active)) {
            return false;
        }
        $this->saveChain($this->withChain($chain, [
            'state' => ChainState::Active,
            'startedAt' => $chain->startedAt ?? $this->clock->now(),
        ]));

        return true;
    }

    public function advanceChain(string $chainId, int $fromStep, int $toStep): bool
    {
        $chain = $this->getChain($chainId);
        if ($chain === null || $chain->currentStep !== $fromStep) {
            return false;
        }
        if ($chain->state !== ChainState::Active && $chain->state !== ChainState::Pending) {
            return false;
        }
        $this->saveChain($this->withChain($chain, [
            'state' => ChainState::Active,
            'currentStep' => $toStep,
            'startedAt' => $chain->startedAt ?? $this->clock->now(),
        ]));

        return true;
    }

    public function completeChain(string $chainId): bool
    {
        $chain = $this->getChain($chainId);
        if ($chain === null) {
            return false;
        }
        if ($chain->state === ChainState::Completed) {
            $this->redis->command('SREM', [$this->keys->chainsIncomplete(), $chainId]);

            return true;
        }
        if ($chain->state !== ChainState::Active && $chain->state !== ChainState::Pending) {
            return false;
        }
        $this->saveChain($this->withChain($chain, [
            'state' => ChainState::Completed,
            'completedAt' => $this->clock->now(),
        ]));
        $this->redis->command('SREM', [$this->keys->chainsIncomplete(), $chainId]);

        return true;
    }

    public function failChain(string $chainId, int $failedStep, string $reason, ?string $jobId): bool
    {
        $chain = $this->getChain($chainId);
        if ($chain === null) {
            return false;
        }
        if ($chain->state === ChainState::Failed) {
            $this->redis->command('SREM', [$this->keys->chainsIncomplete(), $chainId]);

            return true;
        }
        if ($chain->state === ChainState::Completed || $chain->state === ChainState::Cancelled) {
            return false;
        }
        $this->saveChain($this->withChain($chain, [
            'state' => ChainState::Failed,
            'failedStep' => $failedStep,
            'failedJobId' => $jobId,
            'failureReason' => $reason,
            'failedAt' => $this->clock->now(),
        ]));
        $this->redis->command('SREM', [$this->keys->chainsIncomplete(), $chainId]);

        return true;
    }

    public function cancelChain(string $chainId): bool
    {
        $chain = $this->getChain($chainId);
        if ($chain === null || $chain->state === ChainState::Completed || $chain->state === ChainState::Cancelled) {
            return false;
        }
        $this->saveChain($this->withChain($chain, [
            'state' => ChainState::Cancelled,
            'cancelRequested' => true,
            'cancelledAt' => $this->clock->now(),
        ]));
        $this->redis->command('SREM', [$this->keys->chainsIncomplete(), $chainId]);

        return true;
    }

    public function reactivateChain(string $chainId): bool
    {
        $chain = $this->getChain($chainId);
        if ($chain === null || $chain->state !== ChainState::Failed) {
            return false;
        }
        $this->saveChain($this->withChain($chain, [
            'state' => ChainState::Active,
            'failedStep' => null,
            'failedJobId' => null,
            'failureReason' => null,
            'failedAt' => null,
        ]));
        $this->redis->command('SADD', [$this->keys->chainsIncomplete(), $chainId]);

        return true;
    }

    public function listChains(int $limit = 50): array
    {
        $ids = $this->redis->command('ZREVRANGE', [$this->keys->chainsAll(), '0', (string) ($limit - 1)]);
        if (!is_array($ids)) {
            return [];
        }
        $out = [];
        foreach ($ids as $id) {
            $chain = $this->getChain((string) $id);
            if ($chain !== null) {
                $out[] = $chain;
            }
        }

        return $out;
    }

    public function incompleteChains(int $limit = 50): array
    {
        $ids = $this->redis->command('SMEMBERS', [$this->keys->chainsIncomplete()]);
        if (!is_array($ids)) {
            return [];
        }
        $out = [];
        foreach ($ids as $id) {
            $chain = $this->getChain((string) $id);
            if ($chain !== null) {
                $out[] = $chain;
            }
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    public function pruneChains(\DateTimeImmutable $completedBefore, \DateTimeImmutable $failedBefore, int $limit): int
    {
        $deleted = 0;
        foreach ($this->listChains(500) as $chain) {
            if ($deleted >= $limit) {
                break;
            }
            $drop = false;
            if ($chain->state === ChainState::Completed || $chain->state === ChainState::Cancelled) {
                $cut = $chain->completedAt ?? $chain->cancelledAt ?? $chain->createdAt;
                $drop = $cut <= $completedBefore;
            } elseif ($chain->state === ChainState::Failed) {
                $cut = $chain->failedAt ?? $chain->createdAt;
                $drop = $cut <= $failedBefore;
            }
            if (!$drop) {
                continue;
            }
            $this->redis->command('DEL', [$this->keys->chain($chain->chainId)]);
            for ($i = 1; $i <= $chain->totalSteps; $i++) {
                $this->redis->command('DEL', [$this->keys->chainStep($chain->chainId, $i)]);
            }
            $this->redis->command('ZREM', [$this->keys->chainsAll(), $chain->chainId]);
            $this->redis->command('SREM', [$this->keys->chainsIncomplete(), $chain->chainId]);
            $deleted++;
        }

        return $deleted;
    }

    public function createHeader(BatchRecord $batch): void
    {
        $this->saveBatch($batch);
        $this->redis->command('SADD', [$this->keys->batchesCreating(), $batch->batchId]);
        $this->redis->command('SADD', [$this->keys->batchesIncomplete(), $batch->batchId]);
        $this->redis->command('ZADD', [$this->keys->batchesAll(), (string) $batch->createdAt->getTimestamp(), $batch->batchId]);
    }

    public function saveMembers(array $members): void
    {
        foreach ($members as $member) {
            $existing = $this->getMember($member->batchId, $member->memberIndex);
            if ($existing !== null && $existing->status !== MemberStatus::Waiting) {
                continue;
            }
            $this->saveMember($member);
        }
    }

    public function getBatch(string $batchId): ?BatchRecord
    {
        $raw = $this->redis->command('GET', [$this->keys->batch($batchId)]);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? BatchRecord::fromArray($data) : null;
    }

    public function getMember(string $batchId, int $index): ?BatchMemberRecord
    {
        $raw = $this->redis->command('GET', [$this->keys->batchMember($batchId, $index)]);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $this->memberFromArray($data) : null;
    }

    public function waitingMembers(string $batchId, int $limit = 100): array
    {
        return $this->membersWithStatus($batchId, MemberStatus::Waiting, $limit);
    }

    public function dispatchedMembers(string $batchId, int $limit = 100): array
    {
        return $this->membersWithStatus($batchId, MemberStatus::Dispatched, $limit);
    }

    public function markMemberDispatched(string $batchId, int $index, string $jobId): void
    {
        $member = $this->getMember($batchId, $index);
        if ($member === null) {
            return;
        }
        $this->saveMember($this->copyMember($member, MemberStatus::Dispatched, $jobId));
    }

    public function applyMemberTerminal(string $batchId, int $index, MemberStatus $status): BatchProgress
    {
        $batch = $this->getBatch($batchId);
        $member = $this->getMember($batchId, $index);
        if ($batch === null || $member === null) {
            throw new \Fuzeo\Queue\Exceptions\QueueException('Unknown batch member.');
        }
        $progress = BatchCounters::apply($batch, $member->status, $status, $this->clock->now());
        $this->saveMember($this->copyMember($member, $status, $member->jobId));
        $this->saveBatch($progress->batch);
        if ($progress->batch->state !== BatchState::Creating && $progress->batch->state !== BatchState::Active) {
            $this->redis->command('SREM', [$this->keys->batchesIncomplete(), $batchId]);
            $this->redis->command('SREM', [$this->keys->batchesCreating(), $batchId]);
        }

        return $progress;
    }

    public function activateBatch(string $batchId): bool
    {
        $waiting = $this->waitingMembers($batchId, 1);
        if ($waiting !== []) {
            return false;
        }
        $batch = $this->getBatch($batchId);
        if ($batch === null || $batch->state !== BatchState::Creating) {
            return false;
        }
        $this->saveBatch($this->withBatch($batch, [
            'state' => BatchState::Active,
            'startedAt' => $batch->startedAt ?? $this->clock->now(),
        ]));
        $this->redis->command('SREM', [$this->keys->batchesCreating(), $batchId]);

        return true;
    }

    public function cancelBatch(string $batchId): bool
    {
        $batch = $this->getBatch($batchId);
        if ($batch === null || $batch->state === BatchState::Completed) {
            return false;
        }
        $now = $this->clock->now();
        $state = $batch->state === BatchState::Creating ? BatchState::Cancelled : $batch->state;
        $this->saveBatch($this->withBatch($batch, [
            'cancelRequested' => true,
            'state' => $state,
            'cancelledAt' => $state === BatchState::Cancelled ? $now : $batch->cancelledAt,
        ]));
        if ($state === BatchState::Cancelled) {
            $this->redis->command('SREM', [$this->keys->batchesIncomplete(), $batchId]);
            $this->redis->command('SREM', [$this->keys->batchesCreating(), $batchId]);
        }

        return true;
    }

    public function markFollowUpDispatched(string $batchId, string $kind, string $jobId): bool
    {
        $batch = $this->getBatch($batchId);
        if ($batch === null) {
            return false;
        }
        if ($kind === 'then') {
            if ($batch->thenJobId !== null && $batch->thenJobId !== '') {
                return false;
            }
            $this->saveBatch($this->withBatch($batch, ['thenJobId' => $jobId]));

            return true;
        }
        if ($batch->catchJobId !== null && $batch->catchJobId !== '') {
            return false;
        }
        $this->saveBatch($this->withBatch($batch, ['catchJobId' => $jobId]));

        return true;
    }

    public function recomputeBatch(string $batchId): ?BatchRecord
    {
        $batch = $this->getBatch($batchId);
        if ($batch === null) {
            return null;
        }
        $memory = new MemoryOrchestrationStore($this->clock);
        $memory->createHeader($batch);
        $all = [];
        for ($i = 0; $i < $batch->totalJobs; $i++) {
            $row = $this->getMember($batchId, $i);
            if ($row !== null) {
                $all[] = $row;
            }
        }
        $memory->saveMembers($all);
        $updated = $memory->recomputeBatch($batchId);
        if ($updated !== null) {
            $this->saveBatch($updated);
            if ($updated->state !== BatchState::Active && $updated->state !== BatchState::Creating) {
                $this->redis->command('SREM', [$this->keys->batchesIncomplete(), $batchId]);
                $this->redis->command('SREM', [$this->keys->batchesCreating(), $batchId]);
            }
        }

        return $updated;
    }

    public function listBatches(int $limit = 50): array
    {
        $ids = $this->redis->command('ZREVRANGE', [$this->keys->batchesAll(), '0', (string) ($limit - 1)]);
        if (!is_array($ids)) {
            return [];
        }
        $out = [];
        foreach ($ids as $id) {
            $batch = $this->getBatch((string) $id);
            if ($batch !== null) {
                $out[] = $batch;
            }
        }

        return $out;
    }

    public function incompleteBatches(int $limit = 50): array
    {
        return $this->fromSet($this->keys->batchesIncomplete(), $limit, true);
    }

    public function creatingBatches(int $limit = 50): array
    {
        return $this->fromSet($this->keys->batchesCreating(), $limit, true);
    }

    public function pruneBatches(\DateTimeImmutable $completedBefore, \DateTimeImmutable $failedBefore, int $limit): int
    {
        $deleted = 0;
        foreach ($this->listBatches(500) as $batch) {
            if ($deleted >= $limit) {
                break;
            }
            $drop = false;
            if ($batch->state === BatchState::Completed || $batch->state === BatchState::Cancelled) {
                $cut = $batch->completedAt ?? $batch->cancelledAt ?? $batch->createdAt;
                $drop = $cut <= $completedBefore;
            } elseif ($batch->state === BatchState::Failed) {
                $cut = $batch->failedAt ?? $batch->createdAt;
                $drop = $cut <= $failedBefore;
            }
            if (!$drop) {
                continue;
            }
            $this->redis->command('DEL', [$this->keys->batch($batch->batchId)]);
            for ($i = 0; $i < $batch->totalJobs; $i++) {
                $this->redis->command('DEL', [$this->keys->batchMember($batch->batchId, $i)]);
            }
            $this->redis->command('ZREM', [$this->keys->batchesAll(), $batch->batchId]);
            $this->redis->command('SREM', [$this->keys->batchesIncomplete(), $batch->batchId]);
            $this->redis->command('SREM', [$this->keys->batchesCreating(), $batch->batchId]);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * @return list<BatchRecord>
     */
    private function fromSet(string $key, int $limit, bool $batches): array
    {
        unset($batches);
        $ids = $this->redis->command('SMEMBERS', [$key]);
        if (!is_array($ids)) {
            return [];
        }
        $out = [];
        foreach ($ids as $id) {
            $batch = $this->getBatch((string) $id);
            if ($batch !== null) {
                $out[] = $batch;
            }
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return list<BatchMemberRecord>
     */
    private function membersWithStatus(string $batchId, MemberStatus $status, int $limit): array
    {
        $batch = $this->getBatch($batchId);
        if ($batch === null) {
            return [];
        }
        $out = [];
        for ($i = 0; $i < $batch->totalJobs; $i++) {
            $member = $this->getMember($batchId, $i);
            if ($member !== null && $member->status === $status) {
                $out[] = $member;
                if (count($out) >= $limit) {
                    break;
                }
            }
        }

        return $out;
    }

    private function saveChain(ChainRecord $chain): void
    {
        $this->redis->command('SET', [$this->keys->chain($chain->chainId), json_encode($chain->toArray(), JSON_THROW_ON_ERROR)]);
    }

    private function saveBatch(BatchRecord $batch): void
    {
        $this->redis->command('SET', [$this->keys->batch($batch->batchId), json_encode($batch->toArray(), JSON_THROW_ON_ERROR)]);
    }

    private function saveStep(ChainStepRecord $step): void
    {
        $this->redis->command('SET', [$this->keys->chainStep($step->chainId, $step->stepNumber), json_encode($this->stepToArray($step), JSON_THROW_ON_ERROR)]);
    }

    private function saveMember(BatchMemberRecord $member): void
    {
        $this->redis->command('SET', [$this->keys->batchMember($member->batchId, $member->memberIndex), json_encode($this->memberToArray($member), JSON_THROW_ON_ERROR)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function stepToArray(ChainStepRecord $step): array
    {
        return [
            'chain_id' => $step->chainId,
            'step_number' => $step->stepNumber,
            'job_type' => $step->jobType,
            'schema_version' => $step->schemaVersion,
            'payload' => $step->payload,
            'queue' => $step->queue,
            'priority' => $step->priority,
            'max_attempts' => $step->maxAttempts,
            'timeout_seconds' => $step->timeoutSeconds,
            'status' => $step->status->value,
            'metadata' => $step->metadata,
            'tags' => $step->tags,
            'unique_key' => $step->uniqueKey,
            'job_id' => $step->jobId,
            'parent_job_id' => $step->parentJobId,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function stepFromArray(array $data): ChainStepRecord
    {
        $payload = $data['payload'] ?? [];
        $metadata = $data['metadata'] ?? [];
        $tags = $data['tags'] ?? [];

        return new ChainStepRecord(
            (string) $data['chain_id'],
            (int) $data['step_number'],
            (string) $data['job_type'],
            (int) $data['schema_version'],
            is_array($payload) ? $payload : [],
            (string) $data['queue'],
            (int) $data['priority'],
            (int) $data['max_attempts'],
            (int) $data['timeout_seconds'],
            MemberStatus::from((string) $data['status']),
            is_array($metadata) ? $metadata : [],
            is_array($tags) ? array_values($tags) : [],
            isset($data['unique_key']) && is_string($data['unique_key']) ? $data['unique_key'] : null,
            isset($data['job_id']) && is_string($data['job_id']) ? $data['job_id'] : null,
            isset($data['parent_job_id']) && is_string($data['parent_job_id']) ? $data['parent_job_id'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function memberToArray(BatchMemberRecord $member): array
    {
        return [
            'batch_id' => $member->batchId,
            'member_index' => $member->memberIndex,
            'job_type' => $member->jobType,
            'schema_version' => $member->schemaVersion,
            'payload' => $member->payload,
            'queue' => $member->queue,
            'priority' => $member->priority,
            'max_attempts' => $member->maxAttempts,
            'timeout_seconds' => $member->timeoutSeconds,
            'status' => $member->status->value,
            'metadata' => $member->metadata,
            'tags' => $member->tags,
            'unique_key' => $member->uniqueKey,
            'job_id' => $member->jobId,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function memberFromArray(array $data): BatchMemberRecord
    {
        $payload = $data['payload'] ?? [];
        $metadata = $data['metadata'] ?? [];
        $tags = $data['tags'] ?? [];

        return new BatchMemberRecord(
            (string) $data['batch_id'],
            (int) $data['member_index'],
            (string) $data['job_type'],
            (int) $data['schema_version'],
            is_array($payload) ? $payload : [],
            (string) $data['queue'],
            (int) $data['priority'],
            (int) $data['max_attempts'],
            (int) $data['timeout_seconds'],
            MemberStatus::from((string) $data['status']),
            is_array($metadata) ? $metadata : [],
            is_array($tags) ? array_values($tags) : [],
            isset($data['unique_key']) && is_string($data['unique_key']) ? $data['unique_key'] : null,
            isset($data['job_id']) && is_string($data['job_id']) ? $data['job_id'] : null,
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

    /**
     * @param array<string, mixed> $overrides
     */
    private function withChain(ChainRecord $chain, array $overrides): ChainRecord
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
    private function withBatch(BatchRecord $batch, array $overrides): BatchRecord
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
}
