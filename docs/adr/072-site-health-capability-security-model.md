# ADR-072 Site Health capability and security model

## Context

Phase 8 needs authorization. Site Health must not leak secrets.

## Decision

Custom capabilities with `manage_options` / `manage_network_options` fallbacks. `QueueAccess` is the service boundary. Site Health checks: driver, schema, workers vs pending, Redis policy, generation note. Debug info: versions, schema, driver name, counts, generation, multisite, drop-in, Redis version. Never DSN, passwords, or payloads.

## Alternatives

Only `manage_options` everywhere. Full REST in Phase 7.

## Limitations

Caps are not auto-granted to roles; operators map them.

## Failure behavior

Unauthorized site admins cannot see network jobs (when Phase 8 uses this API).

## Compatibility

Admin menu still unused.

## Phase 8/9

REST must call `QueueAccess`, not stores.
