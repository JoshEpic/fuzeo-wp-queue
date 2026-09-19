<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Operations;

final class AuditEvent
{
    /**
     * @param array<string, scalar|null> $details
     */
    public function __construct(
        public readonly \DateTimeImmutable $occurredAt,
        public readonly string $category,
        public readonly string $action,
        public readonly int $userId,
        public readonly string $resourceType,
        public readonly string $resourceId,
        public readonly int $networkId,
        public readonly int $siteId,
        public readonly array $details = [],
    ) {
    }
}
