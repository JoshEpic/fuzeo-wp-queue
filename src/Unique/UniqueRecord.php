<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Unique;

final class UniqueRecord
{
    public function __construct(
        public readonly string $hash,
        public readonly string $jobId,
        public readonly string $uniqueKey,
        public readonly string $originPackage,
        public readonly string $jobType,
        public readonly ?\DateTimeImmutable $expiresAt,
    ) {
    }
}
