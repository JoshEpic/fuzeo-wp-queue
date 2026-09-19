# ADR-023 Dead-letter semantics

## Context

`failed` already meant “driver.fail() terminal row” in Phase 2. Retryable execution failures must not reuse that name.

## Decision

Canonical stopped state is **`dead`**: the job will not be reserved again without `revive()`. `failed` is retained for the legacy `fail()` API and counts as stopped for CLI/prune. Retryable failures return to **`pending`** with future `available_at`.

Manual retry (`revive`) preserves attempt history, sets `pending`, `attempt = 0`, increments `metadata._retry` lineage via `_replay`. Replay as a **new job id** is deferred.

## Alternatives

- Rename Phase 2 `failed` to `dead` in place: breaks existing rows/tests.
- A separate dead-letter queue table: second reservation system. Rejected.

## Consequences

CLI `wp fuzeo-queue failed` lists `dead` and `failed`. Status prints both.

## Compatibility

`pending → dead` is allowed so poison leftovers at max attempts can stop without a live reservation.

## Future

Phase 6 cancellation stays a distinct `cancelled` state.
