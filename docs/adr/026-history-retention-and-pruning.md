# ADR-026 History retention and pruning

## Context

Phase 2 kept completed rows forever.

## Decision

Configurable retention: `completed_retention_days` (default 7), `dead_retention_days` (default 30). `FailureStore::prune` deletes at most `batch_size` (capped 5000) job ids per category per call, deleting attempt rows for those ids first. CLI: `wp fuzeo-queue prune`.

## Alternatives

- Time-based partitions: operationally heavy for WordPress hosts.
- FK ON DELETE CASCADE: skipped for prefix/plugin portability.

## Consequences

Orphans are avoided by explicit attempt deletes. There is no daemon; operators or a host cron invoke prune.

## Compatibility

No automatic delete on upgrade. Existing completed rows remain until prune runs.

## Future

A scheduled prune worker would be Phase 5+ cron territory and is not implemented here.
