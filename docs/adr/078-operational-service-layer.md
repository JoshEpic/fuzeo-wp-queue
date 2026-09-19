# ADR-078 Operational service layer

## Context

REST, CLI, and admin must not query storage directly.

## Decision

`Fuzeo\Queue\Operations\Operations` is the only product façade for inspection and mutations. It uses `JobCatalog`, `QueueAccess`/`Operator`, `MetricsQuery`, orchestration stores, and `AuditStore`. Retry uses `FailureStore::revive`. Cancel uses Phase 6 `Orchestrator::cancelJob`.

## Alternatives

Thin REST controllers calling drivers. Per-plugin dashboards.

## Performance

Lists are paginated (max 100). Overview uses snapshots + bucketed metrics, not full job scans.

## Security

Server-side `Operator` checks. CLI bypasses HTTP caps (operator shell).

## Failure behavior

`AccessDenied` maps to HTTP 403.

## Compatibility

Driver-neutral catalogs for Memory/MySQL/Redis.

## Future

Phase 9 may add worker process operations behind the same façade.
