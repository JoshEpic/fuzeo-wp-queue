# ADR-047 Idempotency-store semantics

## Context

Handlers need help distinguishing never-started vs in-progress vs completed. Queue cannot wrap closures durably.

## Decision

Explicit API: `begin`, `complete`, `fail`, `heartbeat`, `lookup`. No `run(function)`.

`begin` atomically inserts `started` or returns existing `started`/`completed` without ownership.

Result metadata is small JSON. Completed rows retain until `expires_at` (`idempotency_retain_seconds`, default 7 days).

Scoped like uniqueness (site/network + key), separate types/names from `UniqueStore`.

## Alternatives

Closure helper: crash timing is dishonest. Merge with unique jobs: conflates enqueue and side effects.

## Failure behavior

Store outage fails the handler; it must retry. Completed lookup is the safe skip path.

## Consequences

Document vendor idempotency keys as strictly stronger. Fuzeo store is a coordination tool.

## Compatibility

`fuzeo_queue_idempotency` / Redis idempotency hashes. Capability `idempotency`.
