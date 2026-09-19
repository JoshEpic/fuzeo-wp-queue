# ADR-081 Multisite observability scoping

## Context

Site admins must not see other sites’ jobs or payloads.

## Decision

`Operator::requestedSiteFilter` forces current blog for non-network operators. Network admins may pass `site_id`. Network-scoped jobs require network view. Historical rows for missing sites render `Site {id} (deleted)` via `get_site`.

## Alternatives

Client-side site dropdown only.

## Performance

MySQL filters `site_id` with `lookup_site_state`. Redis applies filters after a bounded scan.

## Security

Server-side only. Site admin cannot select another site.

## Failure behavior

Cross-site inspect throws `AccessDenied` (403).

## Compatibility

Network worker hostnames remain visible to network operators.

## Future

Paginated site picker for huge networks can wrap `get_sites`.
