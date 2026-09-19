# ADR-063 Long-running WordPress runtime model

## Context

WordPress assumes request-scoped PHP. Fuzeo Queue workers boot once and run thousands of jobs.

## Decision

Formal lifecycle: process boot → WordPress → Queue → baseline → loop (prepare, site context, execute, settle, orchestration, reset, health, recycle) → graceful exit. Shared `ProcessLifecycle` is composed into `WorkerLoop` and `SchedulerLoop`. Delivery remains at-least-once.

## Alternatives

Subprocess per job (strong isolation, high cost). Re-bootstrap WordPress per job (too slow). Ignore leakage (unsafe on multisite).

## Limitations

Not a complete request simulator. HTTP hooks and superglobals are not reconstructed as a browser request.

## Failure behavior

Fatals and `exit` die the process. Leases recover jobs. No ACK on shutdown.

## Compatibility

Public job APIs unchanged. New hooks are additive.

## Phase 8/9

Phase 8 may display recycle reasons. Phase 9 may coordinate fleet restarts.
