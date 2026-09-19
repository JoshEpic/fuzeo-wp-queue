# ADR-060 Orchestration reconciliation

## Context

Hooks after commit are lost if the process dies. An internal event bus would over-build Phase 6.

## Decision

No outbox. Worker idle ticks call `Orchestrator::reconcile()`. CLI `wp fuzeo-queue reconcile`. Reconcile:

- incomplete chains: dispatch waiting current step; apply completed/dead job state
- `creating` batches: rematerialize waiting members
- incomplete batches: `recomputeBatch` and follow-ups

Deterministic ids make retries safe.

## Alternatives

Dedicated daemon: extra ops. Outbox consumer: extra tables and a second loop.

## Failure scenarios

Reconcile is bounded (`LIMIT`). Large backlogs need repeated CLI/worker ticks. It never edits rows by hand.

## Consequences

Operators are not required to run a second permanent process.

## Compatibility

Best-effort on mixed fleets; old workers without reconcile still settle jobs, but chains may stall until a 0.6 process runs.

## Future

Phase 7 can document worker fleet restart more strictly; not a new orchestrator daemon.
