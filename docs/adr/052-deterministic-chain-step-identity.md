# ADR-052 Deterministic chain-step identity

## Context

After step A completes, dispatch of B may commit while the chain row does not, or the reverse. Retrying progression must not create two B jobs.

## Decision

`job_id = Ulid::fromMaterial('chain|{chain_id}|{step}', chain_created_ms)`. Enqueue is idempotent on `job_id`. `advanceChain` is a compare-and-set on `current_step`. Duplicate progression attempts enqueue the same B and only one advance wins.

This mirrors Phase 5 occurrence identity (ADR-041 / ADR-049). Chain-step identity is **not** a UniqueStore kind; UniqueJob members still use `unique_key`.

## Alternatives

Random ULIDs + unique constraints on (chain, step): extra unique index and recovery for “did insert happen?”. Outbox table: heavier.

## Failure scenarios

Split brain between job storage and chain store can still yield at-least-once execution of B; operators must keep handlers idempotent.

## Consequences

Recovery is safe without distributed transactions.

## Compatibility

Job ids remain 26-character ULIDs.

## Future

Same pattern used for batch members and follow-ups (ADR-058).
