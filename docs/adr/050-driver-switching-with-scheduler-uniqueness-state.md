# ADR-050 Driver switching with scheduler / uniqueness state

## Context

Phase 4 already refused automatic job migration. Schedules, unique claims, and idempotency records are additional stranded state.

## Decision

**No automatic backend migration in 0.5.** Changing `driver` leaves MySQL tables and Redis keys in place. The new backend starts empty.

`wp fuzeo-queue status` shows configured backend, schema, scheduler heartbeats, and queue counts on the **active** driver only.

Operators must drain jobs, disable or re-register schedules, and accept that unique/idempotency memory is not copied.

## Alternatives

Dual-write: incomplete and racy. Dump/restore tool: Phase 9+ if ever.

## Failure behavior

A switch mid-flight can duplicate work (job on A, replacement on B) or skip schedules until they are saved again on B.

## Consequences

Documented operational migration: unsupported. Diagnostic `driver_switch` from Phase 4 still applies.

## Compatibility

Extends ADR-038; does not change envelope portability.
