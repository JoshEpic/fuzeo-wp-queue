# ADR-053 Chain failure and cancellation semantics

## Context

Operators need to stop a chain and retry a dead step without rebuilding the chain.

## Decision

- Retryable handler failure: chain stays `active`; step stays dispatched.
- Terminal `dead`: chain `failed`; `failed_step`, `failed_job_id`, `failure_reason` stored; later steps remain.
- `wp fuzeo-queue chains retry` / `Orchestrator::retryChain` revives the dead job (same id) and reactivates the chain. Unique conflicts follow normal unique-job rules.
- Cancel: `cancel_requested` + chain `cancelled`. Current job is cancelled cooperatively. Future steps are not dispatched even if the current step later completes.

## Alternatives

Continue-on-failure: deferred (unclear meaning for sequential imports). Hard-kill PHP: unsafe.

## Failure scenarios

Cancel races with ACK of the current step: if the chain is already cancelled, `onCompleted` does not dispatch the next step.

## Consequences

Cancellation is orchestration-level, not process-level.

## Compatibility

Job state `cancelled` was reserved in Phase 1.

## Future

No DAG edges or approval gates.
