# ADR-044 Atomic unique-job dispatch

## Context

Two HTTP requests can dispatch the same unique job concurrently. Check-then-enqueue is a race.

## Decision

- Jobs may implement `UniqueJob::uniqueKey()` or pass `withUniqueKey()`.
- Driver `enqueue` acquires uniqueness **atomically with** insert: MySQL unique-row insert in the job transaction; Redis `SET key jobId NX`.
- Duplicate is a `DispatchResult` (`accepted=false`, `duplicateOf=holding job id`), not an exception.
- `Queue::dispatch()` still returns `Envelope` (existing or new) so older callers keep compiling.

## Alternatives

Throw on duplicate: noisy for expected suppression. Application-level locks: not durable across PHP processes.

## Failure behavior

If unique insert wins and job insert fails, the transaction rolls back (MySQL). Redis enqueue Lua/scripts must not leave a unique key without a job (release on failure paths).

## Consequences

Capability `atomic_uniqueness`. Conformance plus 20-process races on MySQL and Redis.

## Compatibility

New table `fuzeo_queue_unique` / Redis unique keys. Envelope `unique_key` already existed.
