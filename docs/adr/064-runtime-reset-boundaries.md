# ADR-064 Runtime reset boundaries

## Context

Plugins leak globals, buffers, users, locales, cwd, and transactions.

## Decision

`RuntimeResetter` owns job-boundary cleanup. `WorkerLoop` does not inline cleanup. Restore only known-safe state: user, locale, blog stack, query/post globals, extra output buffers, request globals, cwd, PHP sessions, error/exception handlers when stacked, runtime object-cache flush, unexpected SQL transactions (rollback + recycle). Do not wipe `$wp_filter`, `$wpdb` debug logs, or shared object cache.

## Alternatives

Full process recycle per job. Snapshot all memory.

## Limitations

Shutdown functions, static locals, and arbitrary hooks cannot be reset.

## Failure behavior

Uncertain blog/transaction state → recycle. Never run job B inside job A's transaction.

## Compatibility

Consumers may listen to `fuzeo_queue_runtime_reset`.

## Phase 8/9

Diagnostics in dashboards; not extra reset features.
