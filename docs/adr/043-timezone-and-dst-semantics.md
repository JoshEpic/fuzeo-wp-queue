# ADR-043 Timezone and DST semantics

## Context

Daily “02:00 America/New_York” is a civil time. UTC storage is required. DST creates missing and repeated local hours.

## Decision

- Schedule timezone is explicit (default `UTC`). Process/WordPress timezone is ignored.
- Persist `next_run_at` in UTC.
- **Gap (spring forward):** nonexistent local wall time is skipped; next valid matching instant runs.
- **Fold (fall back):** fire once at the earlier offset. Do not dispatch twice for the same civil time.
- Interval schedules add seconds in UTC (no civil-time DST adjustment).
- Tests use `America/New_York` plus a non-DST zone (`UTC`) with a frozen clock.

## Alternatives

PHP default timezone: host-dependent. Fire both fold instants: surprising duplicates. Shift gap times to 03:00: also valid, but skip is simpler and deterministic.

## Failure behavior

Unknown timezone names throw at registration.

## Consequences

Operators must set `timezone()` for local daily/cron schedules.

## Compatibility

Timezone string stored with the schedule; timestamps remain UTC.
