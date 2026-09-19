# ADR-057 Batch failure policy

## Context

Infrastructure chunks are usually independent. Fail-fast is useful for tightly coupled fan-out.

## Decision

Default **collect-all**. Optional **fail-fast** cancels waiting/pending members after the first dead member; reserved members stay cooperative.

A batch with any dead/unique-conflict member is not `completed`. Manual revive of a dead member (`failed retry`) plus `onRevived` can move a failed batch back toward `active` via recompute; collect-all can still complete if all members later succeed.

## Alternatives

Ignore failures: silent data loss. Per-member ignore lists: workflow language; out of scope.

## Failure scenarios

Fail-fast vs already-running members: those jobs finish or fail on their own; counters include them.

## Consequences

Documented default matches import-chunk workloads.

## Compatibility

Policy stored on the header (`collect-all` / `fail-fast`).

## Future

No additional policies in Phase 6.
