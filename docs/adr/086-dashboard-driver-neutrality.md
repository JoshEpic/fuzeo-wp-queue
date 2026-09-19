# ADR-086 Dashboard driver neutrality

## Context

Operators should not need to know MySQL vs Redis except on Diagnostics.

## Decision

Lists, health, retry, cancel, charts go through `Operations` + `JobCatalog`. Diagnostics may show Redis version/policy or MySQL skip-locked. Redis listing uses existing zsets with a 2000-id scan cap (not `KEYS`).

## Alternatives

SQL-only dashboard. Horizon-like Redis UI.

## Performance

MySQL uses new indexes (`lookup_created`, origin/site/type). Redis pagination is approximate when filters apply.

## Security

DSNs/passwords never rendered (`redactedEndpoint` only).

## Failure behavior

Catalog miss → 400 unknown job.

## Compatibility

Lua v4 unchanged.

## Future

Secondary Redis indexes if filtered list performance requires it.
