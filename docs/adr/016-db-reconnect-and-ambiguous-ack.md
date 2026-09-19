# ADR-016 Database reconnect and ambiguous ACK

## Context

A lost connection after a side effect must not look like success.

## Decision

Ping/reconnect between jobs when `$wpdb->check_connection` / PDO reconnect is available. Query retry only when **not** in a transaction.

ACK: `UPDATE ... WHERE job_id=? AND reservation_token=?`. Zero rows = rejected stale token. Connection errors wrap as `AmbiguousAckException`. The worker must not treat that as completion.

## Alternatives

Retry ACK forever: can ACK after another worker already owns the job if the first ACK actually landed. Dangerous.

## Consequences

At-least-once duplicates after DB loss are expected and correct.
