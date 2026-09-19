<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Orchestration;

interface ChainStore
{
    /**
     * @param list<ChainStepRecord> $steps
     */
    public function createChain(ChainRecord $chain, array $steps): void;

    public function getChain(string $chainId): ?ChainRecord;

    public function getStep(string $chainId, int $stepNumber): ?ChainStepRecord;

    /**
     * @return list<ChainStepRecord>
     */
    public function steps(string $chainId): array;

    public function markStepDispatched(string $chainId, int $stepNumber, string $jobId): void;

    public function markStepStatus(string $chainId, int $stepNumber, MemberStatus $status): void;

    public function activateChain(string $chainId): bool;

    public function advanceChain(string $chainId, int $fromStep, int $toStep): bool;

    public function completeChain(string $chainId): bool;

    public function failChain(string $chainId, int $failedStep, string $reason, ?string $jobId): bool;

    public function cancelChain(string $chainId): bool;

    public function reactivateChain(string $chainId): bool;

    /**
     * @return list<ChainRecord>
     */
    public function listChains(int $limit = 50): array;

    /**
     * @return list<ChainRecord>
     */
    public function incompleteChains(int $limit = 50): array;

    public function pruneChains(\DateTimeImmutable $completedBefore, \DateTimeImmutable $failedBefore, int $limit): int;
}
