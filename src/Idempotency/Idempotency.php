<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Idempotency;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Contracts\ExecutionContextResolver;
use Fuzeo\Queue\Jobs\ExecutionContext;
use Fuzeo\Queue\Support\Ulid;

/**
 * Explicit begin/complete/fail primitives. This is not exactly-once execution.
 *
 * If begin() returns started and the process dies before complete(), Fuzeo cannot
 * know whether the external side effect occurred. Prefer vendor idempotency keys.
 */
final class Idempotency
{
    public function __construct(
        private readonly IdempotencyStore $store,
        private readonly ExecutionContextResolver $context,
        private readonly Clock $clock,
        private readonly int $defaultLeaseSeconds = 60,
        private readonly int $defaultRetainSeconds = 604800,
    ) {
    }

    public function begin(string $key, ?ExecutionContext $context = null, ?int $leaseSeconds = null): IdempotencyBeginResult
    {
        $identity = IdempotencyIdentity::make($key, $context ?? $this->context->current());
        $token = Ulid::generate();

        return $this->store->begin(
            $identity,
            $token,
            $leaseSeconds ?? $this->defaultLeaseSeconds,
        );
    }

    /**
     * @param array<string, mixed> $result JSON-safe metadata only.
     */
    public function complete(string $ownerToken, array $result = [], ?int $retainSeconds = null): void
    {
        $this->store->complete($ownerToken, $result, $retainSeconds ?? $this->defaultRetainSeconds);
    }

    public function fail(string $ownerToken): void
    {
        $this->store->fail($ownerToken);
    }

    public function heartbeat(string $ownerToken, ?int $leaseSeconds = null): bool
    {
        return $this->store->heartbeat($ownerToken, $leaseSeconds ?? $this->defaultLeaseSeconds);
    }

    public function lookup(string $key, ?ExecutionContext $context = null): ?IdempotencyRecord
    {
        return $this->store->lookup(IdempotencyIdentity::make($key, $context ?? $this->context->current()));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }
}
