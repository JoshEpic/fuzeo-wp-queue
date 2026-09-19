<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

final class ActionRecord
{
    /**
     * @param array<string, mixed> $args
     */
    public function __construct(
        public readonly string $id,
        public readonly string $hook,
        public readonly array $args,
        public readonly string $group,
        public readonly string $status,
        public readonly ?\DateTimeImmutable $scheduledAt,
        public readonly ?int $recurrenceSeconds,
        public readonly int $siteId,
        public readonly int $networkId,
    ) {
    }

    public function isRecurring(): bool
    {
        return $this->recurrenceSeconds !== null && $this->recurrenceSeconds > 0;
    }

    public function isInProgress(): bool
    {
        return in_array($this->status, ['in-progress', 'in_progress', 'running'], true);
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['pending', 'async'], true);
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
