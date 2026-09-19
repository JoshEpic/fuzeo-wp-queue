# ADR-048 Idempotency ownership and expiry

## Context

Two workers may `begin` the same key. A crashed owner must not `complete` a newer claim. Abandoned `started` rows cannot last forever.

## Decision

- Owner token (ULID) on `begin`. Mutations `WHERE owner_token = ? AND status = started`.
- Stale complete/fail throws `DriverException`.
- Lease on `started` (default 60s), extend via `heartbeat`. After expiry, purge allows a new `begin`.
- **Expiry ≠ “side effect did not happen.”** Documentation and crash tests keep `started` visible until expiry, then a second owner may run.

## Alternatives

No expiry: stuck forever. Complete without token: stolen completion. Exactly-once claim in docs: rejected.

## Failure behavior

After crash-before-complete, a later owner may double-apply unless the vendor key matches.

## Consequences

Long operations must heartbeat. Operators get no casual reset CLI.

## Compatibility

Token column required; 0.4 had no store.
