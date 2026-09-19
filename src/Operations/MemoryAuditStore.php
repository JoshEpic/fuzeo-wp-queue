<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Operations;

final class MemoryAuditStore implements AuditStore
{
    /** @var list<AuditEvent> */
    private array $events = [];

    public function record(AuditEvent $event): void
    {
        $this->events[] = $event;
        if (count($this->events) > 5000) {
            $this->events = array_slice($this->events, -4000);
        }
    }

    public function recent(int $limit = 50, string $category = ''): array
    {
        $items = $this->events;
        if ($category !== '') {
            $items = array_values(array_filter($items, static fn (AuditEvent $e): bool => $e->category === $category));
        }

        return array_slice(array_reverse($items), 0, max(1, min(200, $limit)));
    }

    public function prune(\DateTimeImmutable $before, int $batchSize = 500): int
    {
        $deleted = 0;
        $kept = [];
        foreach ($this->events as $event) {
            if ($deleted < $batchSize && $event->occurredAt < $before) {
                $deleted++;
                continue;
            }
            $kept[] = $event;
        }
        $this->events = $kept;

        return $deleted;
    }
}
