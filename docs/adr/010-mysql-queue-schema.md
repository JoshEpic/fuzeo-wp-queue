# ADR-010 MySQL queue schema

## Context

Phase 1 stored nothing durably. Workers need indexed rows, not JSON extraction, for reservation.

## Decision

Schema version **2**, network-shared InnoDB tables using `$wpdb->base_prefix`:

- `fuzeo_queue_jobs` — indexed reservation columns plus `envelope` MEDIUMTEXT (full envelope JSON)
- `fuzeo_queue_workers` — process registry
- `fuzeo_queue_meta` — schema version

Indexed: `queue,state,priority,available_at,job_id` and `queue,state,lease_expires_at`.

Completed/failed rows remain in `jobs` for Phase 2 observability. No history table yet.

## Alternatives

Per-blog tables: rejected (Phase 1). Payload-only JSON column without indexes: too slow. Separate blob store: premature.

## Consequences

256 KiB payload default. Larger data lives elsewhere.

## Future

Retention/purge of completed rows can be added without changing reservation.
