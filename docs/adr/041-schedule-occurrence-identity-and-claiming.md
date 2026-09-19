# ADR-041 Schedule occurrence identity and claiming

## Context

Two scheduler processes may see the same due row. Updating `last_run_at` after dispatch races.

## Decision

- `occurrence_id = sha256(schedule_id | intended_run_at UTC atom)`.
- `claimOccurrence` inserts or steals only after lease expiry. Status `claimed` then `dispatched`.
- Claim lease default 30s (`schedule_claim_lease_seconds`). Crash before dispatch: another scheduler recovers the claim.
- `markDispatched` requires the owner token.
- Internally, uniqueness kind `occurrence` also keys `schedule_id|intended_run_at` so a lost “did dispatch commit?” retry cannot enqueue a second job for the same slot.

## Alternatives

Advisory `GET_LOCK` only: not durable. `last_run_at` compare-and-set alone: lost update / double dispatch.

## Failure behavior

A live claim denies the second scheduler (`false`). A `dispatched` claim advances `next_run_at` without enqueueing again.

## Consequences

At-least-once dispatch is still possible if uniqueness and the job insert disagree across a partition; occurrence uniqueness makes that the rare path, not the default.

## Compatibility

`fuzeo_queue_schedule_claims` and Redis claim keys.
