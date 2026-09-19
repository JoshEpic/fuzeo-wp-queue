# ADR-089 Drain semantics

## Context

Deployments need “stop taking work” without queue pause/resume product features.

## Decision

Drain is a fleet token (`drain_generation`). Processes that **see a change after boot** stop reserving, finish the current job, and exit. Processes that **boot during drain** stay alive, do not reserve, and resume if drain is cancelled. Drain is not a job cancel and not SIGKILL. `--timeout` is wait-only; incomplete drain is a non-zero CLI status listing active work.

## Alternatives

Reuse pause. Kill after timeout. Queue-specific drain.

## Races

Supervisor may start replacements during drain; they idle until drain clears. Cancel-drain is supported for processes that have not exited.

## Operations

Use drain before incompatible schema migrate. Use restart for rolling code-only recycle.

## Compatibility

No schema 7.

## 1.0

Global drain only.
