# ADR-004 At-least-once delivery semantics

## Context

Workers crash, leases expire, and deploys interrupt handlers. Exactly-once requires distributed transactions that WordPress sites do not have.

## Decision

The architecture targets **at-least-once** execution. Duplicate delivery is expected. Jobs should be idempotent (`idempotency_key` / `unique_key` exist on the envelope for later drivers).

Reservations carry a token. ACK, release, fail, and extendLease require the active token so a stale worker cannot complete work another worker recovered.

## Alternatives considered

- **Claim exactly-once in docs:** Misleading. Rejected.
- **Exactly-once via outbox in MySQL:** Possible for some jobs later; not a global guarantee.

## Consequences

Side effects must be safe to retry. Phase 1 documents this; retries/backoff themselves are Phase 2+.

## Future implications

Poison queues and dead-lettering remain at-least-once. See [retries](../retries.md).
