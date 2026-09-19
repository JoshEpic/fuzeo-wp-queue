# ADR-011 Atomic MySQL reservation algorithm

## Context

Two workers must not share an active reservation. Ancient MySQL lacks `SKIP LOCKED`.

## Decision

Require MySQL 8.0.1+ or MariaDB 10.6+. Reserve inside a transaction:

`SELECT ... FOR UPDATE SKIP LOCKED` then `UPDATE` token, lease, worker, state=reserved.

Expired reserved rows are eligible in the same SELECT (`lease_expires_at <= now`). No separate sweeper is required.

## Alternatives

`GET_LOCK(job_id)`: extra round-trips. `UPDATE ... LIMIT 1` without skip locked: lock waits and pile-ups. Polling `reserved_at IS NULL` like older Laravel: weaker under concurrency.

## Consequences

Unsupported databases fail health/reserve with a clear error.

## Future

Redis blocking reserve is a later driver, not a MySQL fake.
