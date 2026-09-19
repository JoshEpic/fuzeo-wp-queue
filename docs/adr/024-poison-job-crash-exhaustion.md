# ADR-024 Poison-job crash exhaustion

## Context

A handler that `SIGKILL`s the process never runs PHP `catch`. Lease recovery would otherwise re-execute forever at `attempt = 1` if attempts incremented only on exceptions.

## Decision

Reservation increments attempts. After lease expiry, if `attempt >= max_attempts`, the next reservation transaction dead-letters the row without invoking the handler.

## Alternatives

- Supervisor max-restarts only: not portable and not job-scoped.
- Increment on lease recovery without a new reservation: races with a live owner.

## Consequences

`max_attempts = 5` allows at most five crash recoveries then `dead`. Missing failure rows for fatals are expected.

## Compatibility

Restart workers when deploying this semantics next to 0.2 processes (see ADR-027).

## Future

Process-level crash metrics belong to Phase 8, not this counter.
