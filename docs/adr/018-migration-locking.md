# ADR-018 Migration locking under multiple bundled consumers

## Context

Option locks were not safe under concurrent PHP processes.

## Decision

`GET_LOCK('fuzeo_queue_schema', timeout)` on the MySQL connection. One runner. Bounded wait. `RELEASE_LOCK` in finally.

Schema owner remains `fuzeowp/queue`. Compatible bundled copies share the loaded runtime; only that runtime migrates.

## Alternatives

Filesystem locks: wrong on NFS. Named MySQL tables as mutex without GET_LOCK: easy to leak.

## Consequences

Requires a real MySQL connection for production migrations. Memory tests keep the in-process lock.
