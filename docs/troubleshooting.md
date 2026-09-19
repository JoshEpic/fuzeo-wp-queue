# Operational troubleshooting

Fuzeo Queue is **at-least-once**. If a worker crashes after a side effect and before ACK, the job will run again after the lease expires.

## Worker isn't processing

- Is `wp fuzeo-queue work` running?
- `--queue` must match the dispatched queue name.
- `wp fuzeo-queue queues` shows eligible depth.
- Jobs with future `available_at` wait.

## Queue table missing

Schema version must be 2. Boot the runtime inside WordPress (`plugins_loaded`) so Fuzeo Queue owns the migration. `wp fuzeo-queue status` reports schema/driver health.

## Stale worker

`wp fuzeo-queue workers` marks heartbeat age. Stale **does not** steal jobs. Job ownership is the **lease**.

## Database unavailable

Failed ACK is never treated as success (`AmbiguousAckException`). The job stays reserved until the lease expires, then another worker may execute it.

## Unsupported job type / plugin deactivated

The worker **fails** the job. It does not invent a handler or unserialize a PHP class.

## Site no longer exists

Site-scoped jobs **fail**. They never `switch_to_blog` into the main site as a fallback.

## Duplicate execution example

1. Handler charges a card.
2. Process is `kill -9` before ACK.
3. Lease expires.
4. Another worker runs the handler again.

Make handlers idempotent (charge keys, row upserts, etc.). Retry/dead-letter policies are Phase 3.
