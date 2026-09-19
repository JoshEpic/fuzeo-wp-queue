# ADR-090 Deployment state persistence

## Context

Restart/drain/maintenance must survive web vs worker processes and must not depend on Redis when MySQL is the queue driver.

## Decision

`DeploymentStore` (memory, MySQL `fuzeo_queue_meta` keys, Redis `prefix:deploy` hash). Not part of `QueueDriver`. MySQL uses `GET_LOCK(fuzeo_queue_deploy)`. Redis uses SET NX lock. Maintenance has owner token + expiry; stale owners cannot release newer state.

## Alternatives

WordPress options only. QueueDriver methods. Schema v7 table.

## Races

Two migrate/drain commands serialize on the lock. Redis down during restart request: Redis store throws; no false success.

## Operations

Control plane follows the configured backend. Memory store is in-process (tests).

## Compatibility

No schema bump; meta table already exists.

## 1.0

Keep the store backend-neutral.
