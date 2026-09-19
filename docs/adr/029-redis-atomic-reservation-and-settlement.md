# ADR-029 Redis atomic reservation and settlement

## Context

Two workers must never share an active reservation. Retry/dead settlement must not split-brain.

## Decision

Small versioned Lua scripts (`RedisScripts::VERSION`) executed via EVALSHA with NOSCRIPT reload:

- enqueue, reserve, ack, release, settle, extend, revive, lock_release, lock_extend

Reserve: promote due delayed jobs, recover expired leases (dead-letter if attempts exhausted), admit concurrency/rate, then increment attempt, set token/lease, move to reserved.

ACK/release/settle/extend compare the stored token and abort with 0 on mismatch.

## Alternatives

MULTI/EXEC without Lua cannot atomically mix zset picks and hash updates as easily. One giant script was rejected.

## Consequences

Script cache is not assumed to survive `SCRIPT FLUSH` or restart.

## Compatibility

Same reservation token and attempt semantics as MySQL (ADR-011, ADR-019, ADR-025).
