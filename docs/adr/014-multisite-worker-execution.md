# ADR-014 WordPress multisite worker execution

## Context

Workers often boot on the main site. Jobs carry `network_id`, `site_id`, `scope`.

## Decision

Site-scoped: `switch_to_blog($site_id)` in try/finally `restore_current_blog()`. Missing sites fail the job; never fall back to blog 1.

Network-scoped: do not switch. Execute in the worker's current (typically network-aware) context.

## Alternatives

Always switch: wrong for network jobs. Infer site at execute time: loses dispatch intent.

## Consequences

Deleted, archived, and spam sites are not executable. Nested `switch_to_blog()` is unwound to the worker baseline ([ADR-065](065-multisite-context-restoration.md)).
