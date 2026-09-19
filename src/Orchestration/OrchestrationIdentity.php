<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Orchestration;

use Fuzeo\Queue\Support\Ulid;

final class OrchestrationIdentity
{
    public static function chainStep(string $chainId, int $step, int $milliseconds): string
    {
        return Ulid::fromMaterial('chain|' . $chainId . '|' . $step, $milliseconds);
    }

    public static function batchMember(string $batchId, int $index, int $milliseconds): string
    {
        return Ulid::fromMaterial('batch|' . $batchId . '|' . $index, $milliseconds);
    }

    public static function followUp(string $batchId, string $kind, int $milliseconds): string
    {
        return Ulid::fromMaterial('follow|' . $batchId . '|' . $kind, $milliseconds);
    }

    public static function timestampMs(\DateTimeImmutable $at): int
    {
        return ((int) $at->format('U')) * 1000 + (int) $at->format('v');
    }
}
