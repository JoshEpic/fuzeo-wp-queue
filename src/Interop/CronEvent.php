<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

final class CronEvent
{
    /**
     * @param list<mixed> $args
     */
    public function __construct(
        public readonly string $hook,
        public readonly int $timestamp,
        public readonly array $args,
        public readonly string|false $recurrence,
        public readonly ?int $intervalSeconds,
        public readonly int $siteId,
        public readonly int $networkId,
        public readonly string $eventKey,
    ) {
    }

    public function isRecurring(): bool
    {
        return $this->recurrence !== false && $this->recurrence !== '';
    }

    public function sourceIdentifier(): string
    {
        return 'cron:' . $this->hook . ':' . $this->eventKey . ':' . $this->siteId;
    }
}
