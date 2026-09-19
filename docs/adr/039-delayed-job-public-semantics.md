# ADR-039 Delayed job public semantics

## Context

Phases 2–4 already stored `available_at`. Developers needed a documented, typed delay API and identical eligibility on MySQL, Redis, and Memory.

## Decision

- Public entry: `Queue::later($when, $job)` plus `PendingDispatch::later($when)` / `delay($seconds)`.
- Inputs: `DateTimeInterface`, unix `int` (0–2100), relative `+/-` strings against the injectable clock, other parseable strings.
- Normalize to UTC. Reject empty, malformed, and out-of-range values with `InvalidDelayException`. Negative `delay()` seconds are invalid.
- **Past instants remain past and are immediately reservable.**
- No second delay engine. Drivers already honor `available_at`.

## Alternatives

Fluent `dispatch()->delay()` as the only syntax: rejected; `Queue::later` matches existing `Queue::dispatch` style. Multiple redundant aliases: rejected.

## Failure behavior

Invalid input fails at dispatch, not at reserve. A past timestamp never throws.

## Consequences

Conformance `testFutureEligibility` plus Phase 5 delay tests. Clock injection avoids `sleep()` in unit tests.

## Compatibility

Envelope field unchanged. Schema unchanged for delays.
