# ADR-115 Multisite interoperability scoping

## Decision

Cron/AS inspection and migration are site-scoped. Site admins cannot migrate another site. Network manage uses existing `QueueAccess`. Deleted site IDs remain on historical rows.
