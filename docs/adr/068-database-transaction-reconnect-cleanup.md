# ADR-068 Database transaction and reconnect cleanup

## Context

A handler may `START TRANSACTION` and throw. Phase 2 reconnect must not ACK with a lost connection.

## Decision

Between jobs: ping, reconnect only when not in an ambiguous in-flight ACK. Detect open transactions via PDO `inTransaction()`, MariaDB `@@in_transaction`, or `information_schema.innodb_trx`. Rollback unexpected transactions and recycle. Do not reconnect in the middle of an ambiguous transaction. Redis `DISCARD` between jobs. Do not `RELEASE_LOCK` for locks Queue does not hold.

## Alternatives

Always `ROLLBACK` even when detection fails (may warn). Ignore transactions (unsafe).

## Failure behavior

Job B never inherits job A's transaction. Reconnect failure after backoff → worker exit. False ACK remains forbidden (`AmbiguousAckException`).

## Compatibility

Schema unchanged.

## Phase 8/9

Surface `runtime.db_reconnected` / `runtime.transaction_rolled_back` in ops UI.
