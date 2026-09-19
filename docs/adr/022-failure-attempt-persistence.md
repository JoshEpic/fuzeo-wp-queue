# ADR-022 Failure and attempt persistence

## Context

Operators need attempt history without duplicating the full envelope on every failure.

## Decision

Schema v3 adds `{prefix}fuzeo_queue_attempts`. One table covers failed attempts, retry scheduling, and terminal outcomes. Successful attempts are not stored (completion is on the job row). `FailureStore` is a **separate** interface from `QueueDriver` so transport ACK/reserve stays stable.

MySQL `settleOutcome` writes the attempt row and the job state update in one transaction, token-gated.

## Alternatives

- Separate failures table plus attempts table: redundant.
- Put history only in envelope metadata: unbounded JSON, poor listing.

## Consequences

No foreign keys (WordPress prefix/plugin safety). Prune deletes attempts then jobs. Forged attempt ids cannot mutate other jobs; inserts are worker-produced ULIDs tied to the reserved job.

## Compatibility

`QueueDriver::fail()` still exists and sets `failed` without an attempts row (Phase 2 callers / tests).

## Future

Dashboard (later phase) reads `attemptsFor` / `listStopped`.
