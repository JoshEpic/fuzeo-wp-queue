# ADR-011 Atomic MySQL reservation algorithm

## Context

Two workers must not share an active reservation. Ancient MySQL lacks `SKIP LOCKED`.

## Decision

Require MySQL 8.0.1+ or MariaDB 10.6+. Connections use **session** isolation `READ COMMITTED` (`SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED`). Next-transaction `SET TRANSACTION` is not enough: PDO `beginTransaction()` can start the transaction without applying it, so REPEATABLE READ gap locks remain.

Reservation is two-step: a non-locking ordered candidate read, then `SELECT ... WHERE job_id = ? FOR UPDATE SKIP LOCKED` on the primary key. `ORDER BY priority DESC, available_at ASC` cannot use `reserve_pending` (mixed sort direction), so a single locking `LIMIT 1` filesort examines every matching row and SKIP LOCKED returns nothing to the second worker.

Then `UPDATE` token, lease, worker, state=reserved.

Default REPEATABLE READ next-key/gap locks can cover every pending row for the queue even with `LIMIT 1`. A second worker then sees no unlocked candidate, returns null, and (with `--sleep=0`) exits while jobs remain. READ COMMITTED plus a primary-key lock skips only the held row.

Expired reserved rows are eligible in the same SELECT (`lease_expires_at <= now`). No separate sweeper is required.

## Alternatives

`GET_LOCK(job_id)`: extra round-trips. `UPDATE ... LIMIT 1` without skip locked: lock waits and pile-ups. Polling `reserved_at IS NULL` like older Laravel: weaker under concurrency.

## Consequences

Unsupported databases fail health/reserve with a clear error.

## Future

Redis blocking reserve is a later driver, not a MySQL fake.
