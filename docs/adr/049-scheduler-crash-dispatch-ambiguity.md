# ADR-049 Scheduler crash / dispatch ambiguity

## Context

Claim → enqueue → connection drop. The scheduler cannot know if the job row/Redis write committed. Advancing blindly loses the slot; retrying blindly duplicates it.

## Decision

1. Lease the occurrence claim so a crashed claimed-not-dispatched slot is recovered.
2. Acquire uniqueness for `occurrence_id` before enqueue. If acquire loses, treat as already dispatched and advance.
3. On `DriverException` during enqueue, **release** occurrence uniqueness and **do not** mark dispatched / do not advance as success. Another scheduler retries after lease expiry.
4. If enqueue committed but the exception fired anyway, uniqueness still holds; the recovery path hits step 2.

Guarantee: avoidable duplicate occurrences are suppressed. Residual at-least-once remains if uniqueness and job storage diverge (split brain / manual unique release).

## Alternatives

Two-phase commit across MySQL/Redis: not available uniformly. Skip the slot on any error: silent data loss.

## Failure behavior

Backend down: occurrence stays due. Backend up: one job.

## Consequences

Scheduler is an internal consumer of unique-job primitives.

## Compatibility

Requires uniqueness + schedule claims together (schema v4 / Redis keys).
