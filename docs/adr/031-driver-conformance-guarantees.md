# ADR-031 Driver conformance guarantees

## Context

Memory, MySQL, and Redis must expose one programming model.

## Decision

`tests/Conformance/DriverConformanceCases` runs against each driver: enqueue, named queues, priority, delayed eligibility, attempt increment, token ACK/release, lease extend/expiry, stale ACK, retry, dead, revive, poison exhaustion, counts, health, payload, prune.

Driver-specific capabilities have extra tests. CI Redis is release-blocking.

## Alternatives

Separate Redis-only tests would allow semantic drift.

## Compatibility

Phase 3 MySQL tests remain; conformance is additive.
