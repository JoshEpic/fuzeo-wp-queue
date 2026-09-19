# ADR-079 REST API and versioning

## Context

The dashboard and automations need a stable HTTP contract.

## Decision

Namespace `fuzeo-queue/v1`. Routes: overview, queues, jobs, workers, schedules, chains, batches, metrics, diagnostics, health, reconcile, and explicit POST actions (retry/cancel/enable/disable/run). Filters are whitelisted. Page size capped. Winning runtime registers routes once.

## Alternatives

Unversioned `wp/v2`. GraphQL.

## Performance

Bounded list endpoints. No “return all jobs”.

## Security

`permission_callback` plus service-layer `QueueAccess`. Payloads only when `fuzeo_queue_view_payload` or manage. Traces require manage.

## Failure behavior

403/400/409 (`UniqueConflictException`).

## Compatibility

v1 is additive. Breaking changes require v2.

## Future

Keep exporters off this namespace.
