# ADR-054 Batch persistence and membership

## Context

Completed jobs may be pruned; Redis and MySQL differ. Progress cannot be derived only from live queue rows.

## Decision

Persist batch header + `fuzeo_queue_batch_members` (or Redis hashes). Each member has `member_index`, blueprint, status, and optional `job_id`. Envelope `batch_id` is the only linkage model (Phase 1). Members may use different queues. Context and origin inherit from the batch.

Membership is immutable after dispatch.

## Alternatives

Scan jobs by `batch_id`: breaks after prune and across backends. Dynamic add-while-running: completion races; deferred.

## Failure scenarios

Origin plugin deactivated: members dead-letter as unknown jobs; batch rows remain inspectable.

## Consequences

Status APIs never load all members into PHP. CLI uses header counters.

## Compatibility

Schema v5.

## Future

Dashboard can filter by origin without a second membership model.
