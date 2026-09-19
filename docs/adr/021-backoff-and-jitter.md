# ADR-021 Backoff and jitter

## Context

Synchronized retries after an API outage can stampede the dependency.

## Decision

`Backoff` implementations: fixed, linear, exponential (capped), explicit sequence. Optional `PercentJitter` (±N% of the base delay). Jitter uses an injected `RandomSource` (`SystemRandom` or `SequenceRandom` in tests). Jitter is **off** by default. `RetryAfterException` replaces the calculated delay for that failure and does not apply jitter.

## Alternatives

- Full jitter / decorrelated jitter: more moving parts than needed now.
- Required jitter: surprising for small sites.

## Consequences

Unit tests never `sleep()`. Load tests assert distinct `available_at` values when jitter is enabled.

## Compatibility

Policy JSON lives in `metadata._retry`. Older envelopes without it use envelope `max_attempts` and default exponential 30s.

## Future

Phase 4 concurrency limits are independent of backoff.
