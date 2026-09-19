# ADR-019 Attempt accounting

## Context

Retries and poison-job crashes need a monotonic counter. Incrementing only after a successful handler would leave crashing jobs at attempt 1 forever.

## Decision

An attempt begins when reservation succeeds. MySQL increments `attempt` in the same `UPDATE` that writes the reservation token. MemoryDriver matches this for tests. The counter never decreases except `revive()`, which resets to 0 for a new manual cycle and records `_replay`.

## Alternatives

- Increment immediately before `handle()`: a SIGKILL between reserve and increment skips accounting.
- Increment only on thrown exceptions: fatals never increment.

## Consequences

Reserving and then shutting down before `handle()` still consumes an attempt. Reliability over cleverness.

## Compatibility

Jobs persisted with `attempt = 0` pending remain valid. Old 0.2 workers also incremented on reserve.

## Future

Deployment generations (Phase 9) can refuse mixed attempt semantics across runtimes.
