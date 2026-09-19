# ADR-038 Driver switching behavior

## Context

Administrators may change `FUZEO_QUEUE_DRIVER` from mysql to redis.

## Decision

No live migration in Phase 4. Coordinator records `active_driver` in the kernel and emits diagnostic `driver_switch` when it changes: outstanding jobs stay on the previous backend. Envelopes do not embed the driver name (portability). Worker registry lives in the **active** backend (`ProvidesWorkerStore`).

## Alternatives

Automatic copy mysql→redis: incomplete, racy, out of scope.

## Consequences

Drain or replay before switching. `wp fuzeo-queue status` shows the configured backend.
