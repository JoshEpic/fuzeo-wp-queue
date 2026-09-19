# ADR-007 Multisite execution context

## Context

Jobs must not silently follow “whatever blog is current” when a worker process later runs on the main site. Network-wide work is also real.

## Decision

`ExecutionContext` with `network_id`, `site_id`, and `scope`. Site jobs require positive site IDs. Network jobs use `site_id = 0`.

Default resolver uses WordPress current blog/network when dispatching without an override. Explicit `onSite` / `networkScoped` is stored as-is.

Shared queue tables at database/network level, not a cloned schema per blog.

## Alternatives considered

- **Infer site only at execute time:** Loses intent when dispatch happens in a switched blog. Rejected.
- **Per-blog queues only:** Cannot schedule network work cleanly. Rejected.

## Consequences

Workers must `switch_to_blog` for site scope (Phase 2+). Invalid site IDs fail at envelope construction.

## Future implications

Rate limits and uniqueness can include `(network_id, site_id, origin, queue, job_type)`.
