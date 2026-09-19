# ADR-036 Redis connection and reconnect behavior

## Context

Workers must survive brief Redis outages without spinning.

## Decision

`Reconnectable` ping/reconnect on PhpRedis. Failed heartbeat reconnect uses exponential backoff (2^n seconds, cap 30) plus jitter; six failures stop the worker. Blocking reserve uses BLPOP on the last listened queue only so named-queue preference is preserved. Ambiguous ACK on connectivity errors: do not claim completion (`ReliableAcknowledger`).

## Alternatives

Aggressive reconnect storms. Driver-specific branches in WorkerLoop.

## Consequences

Connection backoff is not job retry backoff.
