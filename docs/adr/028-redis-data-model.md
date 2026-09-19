# ADR-028 Redis data model

## Context

Phase 4 adds Redis as a second production backend. SQL tables must not be copied as hashes.

## Decision

Keys live under `fuzeo_queue:{namespace}`:

- `job:{id}` hash: envelope JSON, state, token, worker, lease, available_at
- `ready:{queue}` zset: score `(5000-priority)*10^10 + available_at`
- `delayed:{queue}` zset: score = unix available_at
- `reserved` zset: score = lease expiry
- `dead` / `completed` zsets for inspection and prune
- `attempts:{id}` list of JSON attempt records
- `conc:{queue}` zset of active job ids scored by lease
- `rate:{key}` hash token-bucket state
- `wakeup:{queue}` list for BLPOP
- `worker:{id}` hash + `workers` set
- `lock:{name}` string SET NX EX
- `meta` hash (driver identity)

Promotion of delayed jobs and expired leases happens inside the reserve Lua script.

## Alternatives

One hash per table-equivalent; or Redis Streams. Streams do not give priority + delayed + token ownership without extra structures.

## Consequences

Ready-set ties use member lexicographic order (ULIDs), not a dedicated job_id column.

## Failure behavior

Corrupt envelope JSON fails the operation; the job is not ACKed.
