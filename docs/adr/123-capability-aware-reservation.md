# ADR-123 Capability-aware reservation

## Decision

Optional `ReserveRequest` filters (`executionClass`, `maxTimeoutSeconds`) preserve `QueueDriver` SemVer. Schema 8 indexes `execution_class` and `timeout_seconds`. Redis Lua 5 maintains `compat:{queue}` so incompatible jobs are not popped/released in a tight loop. Persistent workers still reserve from the full ready set.
