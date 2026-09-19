# ADR-067 Object-cache runtime handling

## Context

`wp_cache_flush()` after every job would wipe production Redis/Memcached.

## Decision

Flush only process-local cache: `wp_cache_flush_runtime()` when present, otherwise `wp_cache_switch_to_blog` as a fallback. Detect `wp-content/object-cache.php` for Site Health. Do not couple to specific drop-in vendors.

## Alternatives

Global flush. No cache action (stale group data in default in-memory cache).

## Limitations

Drop-ins may implement runtime flush incorrectly. Statics remain.

## Failure behavior

Jobs must load entities by ID. Cache bugs are recycle/memory issues, not ACK bugs.

## Compatibility

Requires WordPress 6.1+ for `wp_cache_flush_runtime`. Package minimum WordPress is 6.2.

## Phase 8/9

Health may show drop-in presence only.
