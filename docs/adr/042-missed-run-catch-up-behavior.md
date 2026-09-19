# ADR-042 Missed-run / catch-up behavior

## Context

A scheduler offline for hours must not enqueue unbounded work (minute jobs × 90 days).

## Decision

Policies: `skip` (default), `latest`, `all`.

- `skip`: do not backfill; jump to the next future occurrence.
- `latest`: one most recent missed slot if ≥ catch-up cutoff.
- `all`: walk slots until `now`, max `schedule_max_catch_up` (100) and ignore slots older than `schedule_catch_up_cutoff_days` (7). Surplus is truncated and recorded.

## Alternatives

Always catch up all: dangerous default. Infinite `all`: rejected.

## Failure behavior

Cutoff/truncation leave the schedule enabled with `last_result` `skipped_cutoff` or `catch_up_truncated`.

## Consequences

Conservative default favors operator recovery over silent floods. High-frequency `all` still needs explicit config.

## Compatibility

Stored on each schedule row (`catch_up_policy`).
