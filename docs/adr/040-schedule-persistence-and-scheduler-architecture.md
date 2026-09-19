# ADR-040 Schedule persistence and scheduler architecture

## Context

Plugins need recurring work without WP-Cron as the engine and without a second handler runtime.

## Decision

- `ScheduleBook` is the developer API; `ScheduleStore` is driver-owned (MySQL tables, Redis hashes/zsets, Memory maps).
- Redis queues do **not** require MySQL job tables. Schedule state lives in the active backend.
- `Scheduler` only `dispatchRegistered()` ordinary jobs. Workers execute handlers.
- Commands: long-lived `schedule-work`, one-shot `schedule-run`. WP-Cron is out of scope.
- Code owns definitions (register on boot). Persistence holds operational state (`next_run_at`, last result, blocked reason). Fingerprint changes recompute the next run from now.
- `SchedulerLoop` reuses worker options (sleep, memory, max runtime, signals, reconnect) and heartbeats into the schedule store.

## Alternatives

WP-Cron primary: rejected (unreliable under load). Serialized PHP callbacks: rejected. MySQL-only control plane for Redis queues: rejected (forces MySQL).

## Failure behavior

Unknown job type or deleted site blocks the schedule; it is not deleted. Backend errors during dispatch do not advance `next_run_at` as success.

## Consequences

Operators must run a scheduler process (or `schedule-run` on a timer). Full process-manager docs remain Phase 9.

## Compatibility

Schema v4 tables / Redis schedule keys. Capability `scheduling`.
