# ADR-065 Multisite context restoration

## Context

Handlers may call `switch_to_blog()` and not restore. Deleted sites must not execute elsewhere.

## Decision

Site jobs: validate status (missing/deleted/archived/spam → terminal `SiteUnavailableException`), switch, execute, unwind `_wp_switched_stack` to the pre-job depth. Network jobs: no switch; still unwind extra handler switches. Baseline mismatch after reset → recycle. Sites are identified by ID.

## Alternatives

Always switch including network jobs. Ignore nested switches.

## Limitations

Corrupted WordPress switch internals may still require recycle.

## Failure behavior

Fail closed on missing sites. Recycle on unrestorable baseline.

## Compatibility

Envelope context unchanged.

## Phase 8/9

Dashboards must scope by site/network using `QueueAccess`.
