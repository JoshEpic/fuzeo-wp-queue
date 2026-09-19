# ADR-062 Orchestration retention

## Context

Completed chains/batches are useful for a future dashboard but must not grow forever.

## Decision

Reuse job retention windows (`completedRetentionDays` / `deadRetentionDays`). `Orchestrator::prune` deletes terminal chain/batch headers **and** their steps/members when `completed_at`/`cancelled_at`/`failed_at` is older than the matching cutoff. Active/creating rows are never pruned. `wp fuzeo-queue prune` reports `orchestration=` count.

## Alternatives

Keep forever: unbounded. Separate orchestration TTLs: extra config with little gain.

## Failure scenarios

Prune while a follow-up is still pending: follow-up is a normal job; header prune waits until the batch is terminal and aged. Do not prune `creating`/`active`.

## Consequences

History is bounded; dashboard Phase 8 must tolerate missing old headers.

## Compatibility

Same CLI prune command; extra counter only.

## Future

Dashboard should not assume infinite history.
