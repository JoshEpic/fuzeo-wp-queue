# ADR-005 Queue driver contract

## Context

MySQL, memory, and later Redis have different atomic primitives. A lowest-common-denominator interface that lies about capabilities is worse than explicit ops plus a capability bitmap.

## Decision

`QueueDriver` defines `enqueue`, `reserve`, `acknowledge`, `release`, `fail`, `extendLease`, `size`, `health`, `capabilities`.

Return types: `EnqueuedJob`, `Reservation`, `DriverHealth`. Ownership is the reservation token.

Capabilities include priorities, blocking reserve, atomic uniqueness, distributed locks, delayed jobs, metrics, durability.

Phase 1 ships `MemoryDriver` (non-durable, full semantics) and `UnavailableDriver` (default; refuses to pretend durability).

## Alternatives considered

- **Laravel-like `push`/`pop` only:** Too vague for lease safety. Rejected.
- **Implement MySQL now:** Out of Phase 1 scope.

## Consequences

Phase 2 must implement the same ownership rules in SQL (conditional updates on token + lease).

## Future implications

Redis can advertise `blocking_reserve` without forcing MySQL to fake `BRPOP`.
