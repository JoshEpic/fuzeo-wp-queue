# ADR-012 Lease and visibility semantics

## Context

Job timeout and reservation lease are easy to conflate.

## Decision

- **Timeout**: how long the handler should run (soft `pcntl_alarm`).
- **Lease**: how long this worker owns the row. Default 90s vs timeout 60s. Workers reserve with `max(lease, timeout+15)`.

Long jobs should set a large timeout **and** matching lease. Phase 2 extends lease only via `extendLease` (token-gated), not on a background thread during `handle()`.

Expired leases are reserved by the next worker. That is recovery, not a retry policy.

## Alternatives

Lease = timeout always: too tight if shutdown runs after the handler. Lease much longer than timeout: slow crash recovery.

## Consequences

Operators must size `--lease` ≥ `--timeout`.
