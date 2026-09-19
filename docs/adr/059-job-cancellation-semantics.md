# ADR-059 Job cancellation semantics

## Context

Phase 1 reserved `cancelled`. Operators need to stop pending work without SIGKILL of workers.

## Decision

- Pending + cancel: atomic `cancelled`; uniqueness released; reserve must not succeed afterward (MySQL `FOR UPDATE` / Redis Lua / memory flag).
- Reserved + cancel: `cancel_requested` (MySQL column / Redis hash / memory). Worker checks before handler and after. `settleCancelled` with the reservation token. Handlers may poll `JobContext::isCancellationRequested()`.
- Cancelled jobs do not retry. No revive-from-cancelled.
- CLI `wp fuzeo-queue cancel <id> --force`.

Pause/resume of admission is deferred so WorkerLoop stays simple.

## Alternatives

Kill the worker process: drops unrelated jobs. Persist a `running` job state: Phase 1 rejected it.

## Failure scenarios

Cancel vs reserve: one transaction wins. If reserve wins, outcome is `cancel_requested`, never “pending cancelled then executed”.

## Consequences

Hard cancellation is not a product promise.

## Compatibility

Envelope unchanged. Schema v5 adds `cancel_requested` on jobs.

## Future

Queue pause may land in operator/observability phases.
