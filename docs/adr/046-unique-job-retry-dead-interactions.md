# ADR-046 Unique-job retry / dead interactions

## Context

Retries are the same logical job. Dead jobs have finished automatic execution. Operators may revive a dead job after a replacement was dispatched.

## Decision

- `settleOutcome` pending/retry does **not** release uniqueness.
- Complete and dead **do** release (then optional TTL).
- `revive()` reacquires uniqueness for the same job id. If another job holds the identity, throw `UniqueConflictException`. No silent dual-active unique jobs.
- CLI `failed retry` surfaces the conflict. `unique release --force` is the only override and is warned.

## Alternatives

Hold uniqueness forever after dead: blocks legitimate replacements. Release on first failure: allows duplicate while retrying.

## Failure behavior

Manual retry conflict is explicit. Force-release can create two active equivalents; operators accept that.

## Consequences

Dead-letter uniqueness is a policy, not a lock manager UI.

## Compatibility

Revive behavior is new in 0.5; previous revive had no unique table.
