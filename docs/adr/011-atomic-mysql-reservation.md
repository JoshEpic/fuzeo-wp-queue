# ADR-011 Atomic MySQL reservation algorithm

## Context

Two workers must not share an active reservation. Ancient MySQL lacks `SKIP LOCKED`.

## Decision

Require MySQL 8.0.1+ or MariaDB 10.6+. Reserve inside a transaction whose isolation level is **READ COMMITTED**:

`SET TRANSACTION ISOLATION LEVEL READ COMMITTED` then `SELECT ... FOR UPDATE SKIP LOCKED` then `UPDATE` token, lease, worker, state=reserved.

Default REPEATABLE READ next-key/gap locks can cover every pending row for the queue even with `LIMIT 1`. A second worker then sees no unlocked candidate, returns null, and (with `--sleep=0`) exits while jobs remain. READ COMMITTED locks only the chosen row, which is what SKIP LOCKED needs for concurrent workers.

Expired reserved rows are eligible in the same SELECT (`lease_expires_at <= now`). No separate sweeper is required.

## Alternatives

`GET_LOCK(job_id)`: extra round-trips. `UPDATE ... LIMIT 1` without skip locked: lock waits and pile-ups. Polling `reserved_at IS NULL` like older Laravel: weaker under concurrency.

## Consequences

Unsupported databases fail health/reserve with a clear error.

## Future

Redis blocking reserve is a later driver, not a MySQL fake.
