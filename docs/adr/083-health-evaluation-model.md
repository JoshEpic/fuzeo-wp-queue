# ADR-083 Health evaluation model

## Context

Dashboard and Site Health must agree without alarm spam.

## Decision

`HealthStatus`: healthy | degraded | critical | unknown. Signals: driver down (critical), lag vs configurable seconds, dead count, pending with zero live workers, metrics degraded. Scheduler absence is not critical when there are no schedules. Site Health worker test uses the same no-worker copy.

## Alternatives

Page every spike. External alerting (deferred).

## Performance

Uses catalog snapshots, not full history.

## Security

No credentials in messages.

## Failure behavior

Unknown only when runtime is unbooted.

## Compatibility

Site Health badges remain good/recommended/critical (WP vocabulary) mapped from the same signals.

## Future

Notification channels are Phase 9+.
