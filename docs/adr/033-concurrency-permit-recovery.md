# ADR-033 Concurrency permit recovery

## Context

A SIGKILL must not pin a concurrency slot forever.

## Decision

Couple permits to reservation lifetime. Redis stores the job id in `conc:{queue}` with score = lease expiry; reserve removes expired members before counting. ACK/release/settle ZREM the id. MySQL GET_LOCK is released on connection death. Memory counts live reservations only.

## Alternatives

Separate permit TTL unrelated to job lease would desync ownership.

## Consequences

A crashed Redis worker frees the slot when the job lease expires, the same moment the job becomes reservable again.
