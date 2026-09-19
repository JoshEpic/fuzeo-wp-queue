# ADR-051 Chain persistence and progression

## Context

Plugin authors need sequential work (fetch → transform → import) without each handler enqueueing the next step. Phase 6 must stay at-least-once and must not become a DAG engine.

## Decision

Persist `fuzeo_queue_chains` + `fuzeo_queue_chain_steps` (Redis hashes/indexes; memory maps). Steps store registered `job_type` + JSON payload, never PHP objects. Only the current step is materialized onto the live queue. `Orchestrator` owns progression; `WorkerLoop` only notifies `onCompleted` / `onDead` / `onCancelled`.

Default policy is stop-on-terminal-failure. Pause/resume is not implemented.

## Alternatives

Enqueue all steps immediately and gate in handlers: races, leaked jobs, handlers become coordinators. Transactional outbox/event bus: extra product surface; deterministic ids + reconcile suffice.

## Failure scenarios

Process dies after ACK and before next enqueue: step stays complete, `current_step` may not advance. Reconcile inspects the current step’s job state and dispatches the next id.

## Consequences

Chains are inspectable after origin deactivation. Unknown future step types dead-letter that step and fail the chain (fail closed).

## Compatibility

Schema v5. Envelope `chain_id` / `parent_job_id` from Phase 1.

## Future

Continue-on-failure only if a later phase defines clear semantics. No branching.
