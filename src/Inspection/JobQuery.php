<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Inspection;

use Fuzeo\Queue\Jobs\JobState;

final class JobQuery
{
    public const MAX_LIMIT = 100;

    public function __construct(
        public readonly ?JobState $state = null,
        public readonly ?string $queue = null,
        public readonly ?string $jobType = null,
        public readonly ?string $origin = null,
        public readonly ?int $siteId = null,
        public readonly ?string $jobId = null,
        public readonly ?string $tag = null,
        public readonly ?\DateTimeImmutable $createdAfter = null,
        public readonly ?\DateTimeImmutable $createdBefore = null,
        public readonly int $limit = 25,
        public readonly int $offset = 0,
    ) {
    }

    public function bounded(): self
    {
        $limit = max(1, min(self::MAX_LIMIT, $this->limit));
        $offset = max(0, min(1000000, $this->offset));

        return new self(
            $this->state,
            $this->queue,
            $this->jobType,
            $this->origin,
            $this->siteId,
            $this->jobId,
            $this->tag,
            $this->createdAfter,
            $this->createdBefore,
            $limit,
            $offset,
        );
    }
}
