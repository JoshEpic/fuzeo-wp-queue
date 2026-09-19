# ADR-084 Operational audit events

## Context

Retries and schedule changes need a bounded trail, not a compliance platform.

## Decision

`fuzeo_queue_audit` / Redis list. Categories `audit` (user actions) and `event` (infrastructure). Fields: action, user_id, resource, site, timestamp, small JSON details. Cap ~2000 Redis entries; MySQL prune 30 days. No PII beyond user ID.

## Alternatives

Write every job attempt. Ship to an SIEM.

## Performance

Best-effort; failures are swallowed.

## Security

No payloads in details.

## Failure behavior

Audit write failure does not fail the action.

## Compatibility

`wp fuzeo-queue prune` also prunes metrics/audit.

## Future

Export hooks can stream `AuditEvent`.
