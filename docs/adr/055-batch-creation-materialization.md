# ADR-055 Batch creation and materialization

## Context

Tens of thousands of members cannot be one MySQL transaction. A crash after 5,000 inserts must not duplicate members or mark the batch complete.

## Decision

States: `creating` → `active` → terminal. Header is written first with fixed `total_jobs`. Members are saved and materialized in chunks of 100. `activateBatch` succeeds only when no `waiting` members remain. Member `job_id = Ulid::fromMaterial('batch|{id}|{index}', created_ms)`.

Reconciliation resumes waiting members. Cancel during `creating` stops materialization.

UniqueJob conflicts mark `unique_conflict` and count as failed; `total_jobs` is unchanged.

## Alternatives

Single giant transaction: locks and packet limits. Two-phase “plan then activate” without deterministic ids: duplicate risk.

## Failure scenarios

Partial materialization: batch stays `creating` until reconcile. Orphan jobs with deterministic ids are reused, not duplicated.

## Consequences

A creating batch is not treated as fully active.

## Compatibility

Schema v5.

## Future

Tune chunk size via config only if measured necessary.
