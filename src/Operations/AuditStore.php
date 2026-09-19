<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Operations;

interface AuditStore
{
    public function record(AuditEvent $event): void;

    /**
     * @return list<AuditEvent>
     */
    public function recent(int $limit = 50, string $category = ''): array;

    public function prune(\DateTimeImmutable $before, int $batchSize = 500): int;
}
