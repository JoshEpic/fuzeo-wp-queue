<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Idempotency;

final class IdempotencyBeginResult
{
    /**
     * @param array<string, mixed>|null $result
     */
    public function __construct(
        public readonly bool $owned,
        public readonly IdempotencyStatus $status,
        public readonly ?string $ownerToken,
        public readonly ?array $result = null,
    ) {
    }
}
