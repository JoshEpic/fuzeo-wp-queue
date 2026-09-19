<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Idempotency;

use Fuzeo\Queue\Jobs\ExecutionContext;

final class IdempotencyIdentity
{
    public function __construct(
        public readonly string $hash,
        public readonly string $key,
        public readonly ExecutionContext $context,
    ) {
    }

    public static function make(string $key, ExecutionContext $context): self
    {
        $normalized = IdempotencyKey::normalize($key);
        $material = implode("\n", [
            $context->scope->value,
            (string) $context->networkId,
            (string) $context->siteId,
            $normalized,
        ]);

        return new self(hash('sha256', $material), $normalized, $context);
    }
}
