# ADR-058 Batch/chain follow-up dispatch ambiguity

## Context

Last two members may complete together. Completion + follow-up enqueue can split across a crash.

## Decision

Follow-ups are registered jobs. Identity `Ulid::fromMaterial('follow|{batch_id}|{then|catch}', created_ms)`. `markFollowUpDispatched` is CAS on empty `then_job_id` / `catch_job_id`. Enqueue is idempotent on that job id. Reconcile retries if the batch is terminal and the follow-up id is still empty.

Chain next-step dispatch uses the same idea (ADR-052). No closure callbacks. No outbox product.

## Alternatives

Transactional outbox: reliable but an event bus by another name. WordPress hooks after commit: lost on crash.

## Failure scenarios

Two workers both enqueue the follow-up: one job row. Both try to CAS the header: one wins.

## Consequences

At-least-once execution of the follow-up job remains; duplicate *rows* are avoided.

## Compatibility

Envelope `batch_id` and `parent_job_id` as needed.

## Future

Chain-level then/catch was not added; add only with the same identity rules.
