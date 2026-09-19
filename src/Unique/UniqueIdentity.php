<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Unique;

use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\ExecutionContext;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Support\Dates;

/**
 * Canonical uniqueness identity. Installation isolation is the backend (table prefix / Redis namespace).
 *
 * Hash input: kind + origin package + scope + network + site + job type + key.
 */
final class UniqueIdentity
{
    public const KIND_JOB = 'job';

    public const KIND_OCCURRENCE = 'occurrence';

    public const KIND_OVERLAP = 'overlap';

    public function __construct(
        public readonly string $hash,
        public readonly string $kind,
        public readonly string $originPackage,
        public readonly string $jobType,
        public readonly string $uniqueKey,
        public readonly ExecutionContext $context,
    ) {
    }

    public static function forJob(Envelope $envelope): ?self
    {
        if ($envelope->uniqueKey === null || $envelope->uniqueKey === '') {
            return null;
        }

        return self::make(
            self::KIND_JOB,
            $envelope->origin,
            $envelope->jobType,
            $envelope->uniqueKey,
            $envelope->context,
        );
    }

    public static function forOccurrence(
        string $scheduleId,
        \DateTimeImmutable $intendedRunAt,
        Origin $origin,
        ExecutionContext $context,
    ): self {
        return self::make(
            self::KIND_OCCURRENCE,
            $origin,
            'fuzeo.schedule_occurrence',
            $scheduleId . '|' . Dates::toAtom($intendedRunAt),
            $context,
        );
    }

    public static function forScheduleOverlap(string $scheduleId, Origin $origin, ExecutionContext $context): self
    {
        return self::make(
            self::KIND_OVERLAP,
            $origin,
            'fuzeo.schedule_overlap',
            $scheduleId,
            $context,
        );
    }

    public static function make(
        string $kind,
        Origin $origin,
        string $jobType,
        string $uniqueKey,
        ExecutionContext $context,
    ): self {
        $key = UniqueKey::normalize($uniqueKey);
        $material = implode("\n", [
            $kind,
            $origin->package,
            $context->scope->value,
            (string) $context->networkId,
            (string) $context->siteId,
            $jobType,
            $key,
        ]);

        return new self(
            hash: hash('sha256', $material),
            kind: $kind,
            originPackage: $origin->package,
            jobType: $jobType,
            uniqueKey: $key,
            context: $context,
        );
    }
}
