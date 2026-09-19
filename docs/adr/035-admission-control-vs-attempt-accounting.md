# ADR-035 Admission control vs attempt accounting

## Context

Phase 3 increments attempts on successful reservation. Rate/concurrency denial must not burn retries.

## Decision

Admission is part of reserve **before** attempt increment. Peek/lock a candidate, check concurrency and rate, then either delay/skip (attempt unchanged, no token) or increment+own. Redis does this in one Lua script. MySQL holds SKIP LOCKED row, GET_LOCK / rate consume, then UPDATE attempt or `delayLockedPending`. Memory iterates candidates.

Attempt-on-reserve remains true for jobs that actually become reserved.

Peek-then-reserve without a lock was rejected (races). A second “admission lease” state was rejected as a Phase 3 model break.

## Consequences

Handlers still only run after a real reservation. Throttle is not a failure record.
