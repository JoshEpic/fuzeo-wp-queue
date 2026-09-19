<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Orchestration;

use Fuzeo\Queue\Config\Config;
use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Contracts\ExecutionContextResolver;
use Fuzeo\Queue\Core\Dispatcher;
use Fuzeo\Queue\Drivers\CancelsJobs;
use Fuzeo\Queue\Drivers\FailureStore;
use Fuzeo\Queue\Drivers\QueueDriver;
use Fuzeo\Queue\Jobs\DispatchOptions;
use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\ExecutionContext;
use Fuzeo\Queue\Jobs\Job;
use Fuzeo\Queue\Jobs\JobRegistry;
use Fuzeo\Queue\Jobs\JobState;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Support\Ulid;

final class Orchestrator
{
    public function __construct(
        private readonly OrchestrationStore $store,
        private readonly Dispatcher $dispatcher,
        private readonly QueueDriver $driver,
        private readonly Clock $clock,
        private readonly Config $config,
        private readonly JobRegistry $registry,
        private readonly ExecutionContextResolver $contexts,
    ) {
    }

    public function originFor(Job $job): Origin
    {
        return $this->registry->get($job::type())->origin;
    }

    public function currentContext(): ExecutionContext
    {
        return $this->contexts->current();
    }

    public function store(): OrchestrationStore
    {
        return $this->store;
    }

    /**
     * @param list<JobBlueprint> $steps
     * @param array<string, mixed> $metadata
     */
    public function dispatchChain(
        array $steps,
        Origin $origin,
        ExecutionContext $context,
        array $metadata = [],
        ChainFailurePolicy $policy = ChainFailurePolicy::Stop,
    ): ChainRecord {
        if ($steps === []) {
            throw new \Fuzeo\Queue\Exceptions\QueueException('A chain must contain at least one step.');
        }
        $now = $this->clock->now();
        $chainId = Ulid::generate(OrchestrationIdentity::timestampMs($now));
        $ms = OrchestrationIdentity::timestampMs($now);
        $records = [];
        $n = 0;
        foreach ($steps as $blueprint) {
            $n++;
            $jobId = OrchestrationIdentity::chainStep($chainId, $n, $ms);
            $records[] = new ChainStepRecord(
                $chainId,
                $n,
                $blueprint->jobType,
                $blueprint->schemaVersion,
                $blueprint->payload,
                $blueprint->queue,
                $blueprint->priority,
                $blueprint->maxAttempts,
                $blueprint->timeoutSeconds,
                MemberStatus::Waiting,
                $blueprint->metadata,
                $this->mergeTags($blueprint->tags, 'chain'),
                $blueprint->uniqueKey,
                $jobId,
                null,
            );
        }
        $chain = new ChainRecord(
            $chainId,
            $origin,
            $context,
            ChainState::Pending,
            1,
            count($records),
            $policy,
            $now,
            metadata: $metadata,
        );
        $this->store->createChain($chain, $records);
        $this->dispatchChainStep($chain, $records[0], null);
        $this->store->activateChain($chainId);

        return $this->store->getChain($chainId) ?? $chain;
    }

    /**
     * @param list<JobBlueprint> $members
     * @param array<string, mixed> $metadata
     */
    public function dispatchBatch(
        array $members,
        Origin $origin,
        ExecutionContext $context,
        ?string $name = null,
        array $metadata = [],
        BatchFailurePolicy $policy = BatchFailurePolicy::CollectAll,
        ?JobBlueprint $then = null,
        ?JobBlueprint $catch = null,
    ): BatchRecord {
        if ($members === []) {
            throw new \Fuzeo\Queue\Exceptions\QueueException('A batch must contain at least one job.');
        }
        $now = $this->clock->now();
        $batchId = Ulid::generate(OrchestrationIdentity::timestampMs($now));
        $ms = OrchestrationIdentity::timestampMs($now);
        $header = new BatchRecord(
            $batchId,
            $origin,
            $context,
            BatchState::Creating,
            count($members),
            0,
            0,
            0,
            $policy,
            $now,
            $name,
            thenJobType: $then?->jobType,
            thenPayload: $then?->payload,
            thenQueue: $then?->queue,
            catchJobType: $catch?->jobType,
            catchPayload: $catch?->payload,
            catchQueue: $catch?->queue,
            metadata: $metadata,
        );
        $this->store->createHeader($header);
        $chunk = [];
        $index = 0;
        foreach ($members as $blueprint) {
            $jobId = OrchestrationIdentity::batchMember($batchId, $index, $ms);
            $chunk[] = new BatchMemberRecord(
                $batchId,
                $index,
                $blueprint->jobType,
                $blueprint->schemaVersion,
                $blueprint->payload,
                $blueprint->queue,
                $blueprint->priority,
                $blueprint->maxAttempts,
                $blueprint->timeoutSeconds,
                MemberStatus::Waiting,
                $blueprint->metadata,
                $this->mergeTags($blueprint->tags, 'batch'),
                $blueprint->uniqueKey,
                $jobId,
            );
            $index++;
            if (count($chunk) >= 100) {
                $this->persistAndMaterialize($header, $chunk);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $this->persistAndMaterialize($header, $chunk);
        }
        $this->store->activateBatch($batchId);

        return $this->store->getBatch($batchId) ?? $header;
    }

    public function onCompleted(Envelope $envelope): void
    {
        if ($envelope->chainId !== null) {
            $this->onChainStepFinished($envelope, MemberStatus::Completed);
        }
        if ($envelope->batchId !== null) {
            $this->onBatchMemberFinished($envelope, MemberStatus::Completed);
        }
    }

    public function onDead(Envelope $envelope): void
    {
        if ($envelope->chainId !== null) {
            $this->onChainStepFinished($envelope, MemberStatus::Dead);
        }
        if ($envelope->batchId !== null) {
            $this->onBatchMemberFinished($envelope, MemberStatus::Dead);
        }
    }

    public function onCancelled(Envelope $envelope): void
    {
        if ($envelope->chainId !== null) {
            $step = (int) ($envelope->metadata['_chain_step'] ?? 0);
            if ($step > 0) {
                $this->store->markStepStatus($envelope->chainId, $step, MemberStatus::Cancelled);
            }
            $this->store->cancelChain($envelope->chainId);
        }
        if ($envelope->batchId !== null) {
            $index = (int) ($envelope->metadata['_batch_index'] ?? -1);
            if ($index >= 0) {
                $progress = $this->store->applyMemberTerminal($envelope->batchId, $index, MemberStatus::Cancelled);
                if ($progress->justFinalized) {
                    $this->dispatchFollowUps($progress->batch);
                }
            }
        }
    }

    public function onRevived(Envelope $envelope): void
    {
        if ($envelope->chainId !== null) {
            $step = (int) ($envelope->metadata['_chain_step'] ?? 0);
            if ($step > 0) {
                $this->store->markStepStatus($envelope->chainId, $step, MemberStatus::Dispatched);
            }
            $this->store->reactivateChain($envelope->chainId);
        }
        if ($envelope->batchId !== null) {
            $index = (int) ($envelope->metadata['_batch_index'] ?? -1);
            if ($index >= 0) {
                $this->store->markMemberDispatched($envelope->batchId, $index, $envelope->jobId);
                $this->store->recomputeBatch($envelope->batchId);
            }
        }
    }

    public function cancelChain(string $chainId): bool
    {
        $chain = $this->store->getChain($chainId);
        if ($chain === null) {
            return false;
        }
        $ok = $this->store->cancelChain($chainId);
        $step = $this->store->getStep($chainId, $chain->currentStep);
        if ($step?->jobId !== null && $this->driver instanceof CancelsJobs) {
            $this->driver->cancel($step->jobId);
        }

        return $ok;
    }

    public function retryChain(string $chainId): bool
    {
        $chain = $this->store->getChain($chainId);
        if ($chain === null || $chain->state !== ChainState::Failed || $chain->failedJobId === null) {
            return false;
        }
        if (!$this->driver instanceof FailureStore) {
            return false;
        }
        $this->driver->revive($chain->failedJobId);
        $this->store->reactivateChain($chainId);
        if ($chain->failedStep !== null) {
            $this->store->markStepStatus($chainId, $chain->failedStep, MemberStatus::Dispatched);
        }

        return true;
    }

    public function cancelBatch(string $batchId): bool
    {
        $ok = $this->store->cancelBatch($batchId);
        if (!$ok) {
            return false;
        }
        if ($this->driver instanceof CancelsJobs) {
            foreach ($this->store->dispatchedMembers($batchId, 500) as $member) {
                if ($member->jobId !== null) {
                    $this->driver->cancel($member->jobId);
                }
            }
            foreach ($this->store->waitingMembers($batchId, 500) as $member) {
                $this->store->applyMemberTerminal($batchId, $member->memberIndex, MemberStatus::Cancelled);
            }
        }
        $this->store->recomputeBatch($batchId);

        return true;
    }

    public function cancelJob(string $jobId): \Fuzeo\Queue\Drivers\CancelResult
    {
        if (!$this->driver instanceof CancelsJobs) {
            throw new \Fuzeo\Queue\Exceptions\QueueException('The active driver does not support cancellation.');
        }
        $result = $this->driver->cancel($jobId);
        if ($result->isTerminalCancel() && $this->driver instanceof FailureStore) {
            try {
                $envelope = $this->driver->job($jobId);
                $this->onCancelled($envelope);
            } catch (\Fuzeo\Queue\Exceptions\DriverException) {
            }
        }

        return $result;
    }

    public function reconcile(int $limit = 50): int
    {
        $n = 0;
        foreach ($this->store->incompleteChains($limit) as $chain) {
            $n += $this->reconcileChain($chain) ? 1 : 0;
        }
        foreach ($this->store->creatingBatches($limit) as $batch) {
            $n += $this->resumeBatchMaterialization($batch) ? 1 : 0;
        }
        foreach ($this->store->incompleteBatches($limit) as $batch) {
            $fresh = $this->store->recomputeBatch($batch->batchId);
            if ($fresh !== null && $fresh->allTerminal()) {
                $this->dispatchFollowUps($fresh);
                $n++;
            }
        }

        return $n;
    }

    public function prune(\DateTimeImmutable $completedBefore, \DateTimeImmutable $failedBefore, int $limit = 500): int
    {
        return $this->store->pruneChains($completedBefore, $failedBefore, $limit)
            + $this->store->pruneBatches($completedBefore, $failedBefore, $limit);
    }

    private function onChainStepFinished(Envelope $envelope, MemberStatus $status): void
    {
        $chainId = $envelope->chainId;
        if ($chainId === null) {
            return;
        }
        $stepNumber = (int) ($envelope->metadata['_chain_step'] ?? 0);
        $chain = $this->store->getChain($chainId);
        if ($chain === null) {
            return;
        }
        $this->store->markStepStatus($chainId, $stepNumber, $status);
        if ($chain->state === ChainState::Cancelled || $chain->cancelRequested) {
            return;
        }
        if ($status === MemberStatus::Dead) {
            $this->store->failChain($chainId, $stepNumber, 'step_dead', $envelope->jobId);

            return;
        }
        if ($status !== MemberStatus::Completed) {
            return;
        }
        if ($stepNumber !== $chain->currentStep) {
            return;
        }
        if ($stepNumber >= $chain->totalSteps) {
            $this->store->completeChain($chainId);

            return;
        }
        $next = $this->store->getStep($chainId, $stepNumber + 1);
        if ($next === null) {
            return;
        }
        $this->dispatchChainStep($chain, $next, $envelope->jobId);
        $this->store->advanceChain($chainId, $stepNumber, $stepNumber + 1);
    }

    private function onBatchMemberFinished(Envelope $envelope, MemberStatus $status): void
    {
        $batchId = $envelope->batchId;
        if ($batchId === null) {
            return;
        }
        $index = (int) ($envelope->metadata['_batch_index'] ?? -1);
        if ($index < 0) {
            return;
        }
        $progress = $this->store->applyMemberTerminal($batchId, $index, $status);
        $batch = $progress->batch;
        if ($status === MemberStatus::Dead && $batch->failurePolicy === BatchFailurePolicy::FailFast) {
            $this->cancelPendingMembers($batchId);
        }
        $fresh = $this->store->getBatch($batchId) ?? $batch;
        if ($fresh->allTerminal() || $progress->justFinalized) {
            $this->dispatchFollowUps($fresh);
        }
    }

    private function dispatchChainStep(ChainRecord $chain, ChainStepRecord $step, ?string $parentJobId): void
    {
        $ms = OrchestrationIdentity::timestampMs($chain->createdAt);
        $jobId = $step->jobId ?? OrchestrationIdentity::chainStep($chain->chainId, $step->stepNumber, $ms);
        $metadata = $step->metadata;
        $metadata['_chain_step'] = $step->stepNumber;
        $retry = \Fuzeo\Queue\Retry\RetryPolicy::fromArray(
            isset($step->metadata['_retry']) && is_array($step->metadata['_retry']) ? $step->metadata['_retry'] : [],
            $step->maxAttempts
        );
        $options = new DispatchOptions(
            queue: $step->queue,
            priority: $step->priority,
            context: $chain->context,
            origin: $chain->origin,
            chainId: $chain->chainId,
            parentJobId: $parentJobId,
            uniqueKey: $step->uniqueKey,
            metadata: $metadata,
            tags: $step->tags,
            maxAttempts: $step->maxAttempts,
            timeoutSeconds: $step->timeoutSeconds,
            retryPolicy: $retry,
            jobId: $jobId,
        );
        $this->dispatcher->dispatchRegistered($step->jobType, $step->payload, $options);
        $this->store->markStepDispatched($chain->chainId, $step->stepNumber, $jobId);
    }

    /**
     * @param list<BatchMemberRecord> $chunk
     */
    private function persistAndMaterialize(BatchRecord $batch, array $chunk): void
    {
        $this->store->saveMembers($chunk);
        foreach ($chunk as $member) {
            $this->materializeMember($batch, $member);
        }
    }

    private function materializeMember(BatchRecord $batch, BatchMemberRecord $member): void
    {
        $jobId = $member->jobId ?? OrchestrationIdentity::batchMember(
            $batch->batchId,
            $member->memberIndex,
            OrchestrationIdentity::timestampMs($batch->createdAt)
        );
        $metadata = $member->metadata;
        $metadata['_batch_index'] = $member->memberIndex;
        $retry = \Fuzeo\Queue\Retry\RetryPolicy::fromArray(
            isset($member->metadata['_retry']) && is_array($member->metadata['_retry']) ? $member->metadata['_retry'] : [],
            $member->maxAttempts
        );
        $options = new DispatchOptions(
            queue: $member->queue,
            priority: $member->priority,
            context: $batch->context,
            origin: $batch->origin,
            batchId: $batch->batchId,
            uniqueKey: $member->uniqueKey,
            metadata: $metadata,
            tags: $member->tags,
            maxAttempts: $member->maxAttempts,
            timeoutSeconds: $member->timeoutSeconds,
            retryPolicy: $retry,
            jobId: $jobId,
        );
        $result = $this->dispatcher->dispatchRegistered($member->jobType, $member->payload, $options);
        if ($result->accepted || $result->duplicateOf === $jobId || $result->envelope->jobId === $jobId) {
            $this->store->markMemberDispatched($batch->batchId, $member->memberIndex, $jobId);

            return;
        }
        $this->store->markMemberDispatched($batch->batchId, $member->memberIndex, $result->duplicateOf ?? $result->envelope->jobId);
        $this->store->applyMemberTerminal($batch->batchId, $member->memberIndex, MemberStatus::UniqueConflict);
    }

    private function resumeBatchMaterialization(BatchRecord $batch): bool
    {
        for ($chunk = 0; $chunk < 1000; $chunk++) {
            $fresh = $this->store->getBatch($batch->batchId) ?? $batch;
            if ($fresh->cancelRequested) {
                $this->store->cancelBatch($fresh->batchId);

                return true;
            }
            $waiting = $this->store->waitingMembers($fresh->batchId, 100);
            if ($waiting === []) {
                $this->store->activateBatch($fresh->batchId);

                return true;
            }
            foreach ($waiting as $member) {
                $this->materializeMember($fresh, $member);
            }
        }
        $this->store->activateBatch($batch->batchId);

        return true;
    }

    private function reconcileChain(ChainRecord $chain): bool
    {
        if ($chain->cancelRequested || $chain->state === ChainState::Cancelled) {
            return false;
        }
        $step = $this->store->getStep($chain->chainId, $chain->currentStep);
        if ($step === null) {
            return false;
        }
        if ($step->status === MemberStatus::Waiting || ($step->status === MemberStatus::Dispatched && $step->jobId !== null && $this->jobMissingOrPending($step->jobId) === false && $this->jobCompleted($step->jobId))) {
            if ($step->status === MemberStatus::Waiting) {
                $this->dispatchChainStep($chain, $step, null);
                $this->store->activateChain($chain->chainId);

                return true;
            }
        }
        if ($this->jobCompleted($step->jobId)) {
            $fake = $this->envelopeFor($step->jobId);
            if ($fake !== null) {
                $this->onChainStepFinished($fake, MemberStatus::Completed);

                return true;
            }
        }
        if ($this->jobDead($step->jobId)) {
            $fake = $this->envelopeFor($step->jobId);
            if ($fake !== null) {
                $this->onChainStepFinished($fake, MemberStatus::Dead);

                return true;
            }
        }

        return false;
    }

    private function dispatchFollowUps(BatchRecord $batch): void
    {
        if (
            $batch->state === BatchState::Completed
            && $batch->thenJobType !== null
            && $batch->thenPayload !== null
            && ($batch->thenJobId === null || $batch->thenJobId === '')
        ) {
            $this->dispatchFollowUp($batch, 'then', $batch->thenJobType, $batch->thenPayload, $batch->thenQueue ?? $this->config->defaultQueue);
        }
        if (
            $batch->state === BatchState::Failed
            && $batch->catchJobType !== null
            && $batch->catchPayload !== null
            && ($batch->catchJobId === null || $batch->catchJobId === '')
        ) {
            $this->dispatchFollowUp($batch, 'catch', $batch->catchJobType, $batch->catchPayload, $batch->catchQueue ?? $this->config->defaultQueue);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function dispatchFollowUp(BatchRecord $batch, string $kind, string $jobType, array $payload, string $queue): void
    {
        $jobId = OrchestrationIdentity::followUp($batch->batchId, $kind, OrchestrationIdentity::timestampMs($batch->createdAt));
        $options = new DispatchOptions(
            queue: $queue,
            context: $batch->context,
            origin: $batch->origin,
            batchId: $batch->batchId,
            metadata: ['_follow_up' => $kind],
            tags: ['batch'],
            jobId: $jobId,
        );
        $this->dispatcher->dispatchRegistered($jobType, $payload, $options);
        $this->store->markFollowUpDispatched($batch->batchId, $kind, $jobId);
    }

    private function cancelPendingMembers(string $batchId): void
    {
        if (!$this->driver instanceof CancelsJobs) {
            return;
        }
        foreach ($this->store->waitingMembers($batchId, 500) as $member) {
            $this->store->applyMemberTerminal($batchId, $member->memberIndex, MemberStatus::Cancelled);
        }
        foreach ($this->store->dispatchedMembers($batchId, 500) as $member) {
            if ($member->jobId !== null) {
                $this->driver->cancel($member->jobId);
            }
        }
    }

    private function jobCompleted(?string $jobId): bool
    {
        if ($jobId === null || !$this->driver instanceof FailureStore) {
            return false;
        }
        try {
            return $this->driver->job($jobId)->state === JobState::Completed;
        } catch (\Fuzeo\Queue\Exceptions\DriverException) {
            return false;
        }
    }

    private function jobDead(?string $jobId): bool
    {
        if ($jobId === null || !$this->driver instanceof FailureStore) {
            return false;
        }
        try {
            $state = $this->driver->job($jobId)->state;

            return $state === JobState::Dead || $state === JobState::Failed;
        } catch (\Fuzeo\Queue\Exceptions\DriverException) {
            return false;
        }
    }

    private function jobMissingOrPending(?string $jobId): bool
    {
        if ($jobId === null || !$this->driver instanceof FailureStore) {
            return true;
        }
        try {
            $state = $this->driver->job($jobId)->state;

            return $state === JobState::Pending || $state === JobState::Reserved;
        } catch (\Fuzeo\Queue\Exceptions\DriverException) {
            return true;
        }
    }

    private function envelopeFor(?string $jobId): ?Envelope
    {
        if ($jobId === null || !$this->driver instanceof FailureStore) {
            return null;
        }
        try {
            return $this->driver->job($jobId);
        } catch (\Fuzeo\Queue\Exceptions\DriverException) {
            return null;
        }
    }

    /**
     * @param list<string> $tags
     * @return list<string>
     */
    private function mergeTags(array $tags, string $extra): array
    {
        $out = $tags;
        if (!in_array($extra, $out, true)) {
            $out[] = $extra;
        }

        return $out;
    }
}
