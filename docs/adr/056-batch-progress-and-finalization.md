# ADR-056 Batch progress and finalization

## Context

Many members may finish at once. A hot header row can contend; counters can drift if settlement is lost.

## Decision

Each terminal member update adjusts completed/failed/cancelled under a row lock (MySQL `FOR UPDATE`) or Redis/memory equivalent. When `completed+failed+cancelled >= total` the header becomes `completed`, `failed`, or `cancelled`. `recomputeBatch` GROUP BYs membership and is used by reconcile.

Follow-up dispatch is separate (ADR-058).

## Alternatives

Sharded counters: extra complexity for 10k-scale. Pure eventual membership scans on every status read: too slow for CLI.

## Failure scenarios

Settlement applied twice: member status is idempotent; counters decrement previous terminal status before applying the new one.

## Consequences

Correctness first; MySQL header updates are acceptable at planned batch sizes.

## Compatibility

None for older runtimes (schema v5).

## Future

Phase 8 metrics can read these timestamps and counts without new writes.
