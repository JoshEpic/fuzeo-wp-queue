<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Idempotency;

final class IdempotencyRecord
{
    /**
     * @param array<string, mixed>|null $result
     */
    public function __construct(
        public readonly string $hash,
        public readonly string $key,
        public readonly IdempotencyStatus $status,
        public readonly string $ownerToken,
        public readonly \DateTimeImmutable $startedAt,
        public readonly ?\DateTimeImmutable $completedAt,
        public readonly \DateTimeImmutable $expiresAt,
        public readonly ?array $result,
    ) {
    }
}
