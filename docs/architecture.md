# Architecture

## Package layout

```
src/
  bootstrap.php          candidate registration only
  Queue.php              developer facade
  Config/
  Contracts/
  Core/                  QueueManager, Dispatcher
  Drivers/               Memory, MySQL, Redis, Unavailable
  Redis/                 PhpRedis client, keys, Lua scripts
  Concurrency/           queue limits, token bucket
  RateLimit/             declarative policies
  Locks/                 distributed lock contract
  Retry/                 policies, backoff, jitter, classifier
  Unique/                atomic unique-job claims
  Idempotency/           begin/complete store
  Schedule/              definitions, calculator, scheduler loop
  Orchestration/         chains, batches, cancellation, reconcile
  Retention/             prune policy
  Persistence/           migrations, PDO/$wpdb connections, GET_LOCK
  Worker/                loop, identity, timeouts, site switching, handler availability
  Runtime/               coordinator, generation, resetter, process lifecycle
  Deployment/            generation, restart/drain store, readiness
  WordPress/             context, CLI, Site Health, REST, admin, capabilities
  Metrics/               recorder, repositories, histograms
  Operations/            health, audit, dashboard services
  Inspection/            job catalogs
```

## Driver operations

See [ADR-005](adr/005-queue-driver-contract.md) and [ADR-011](adr/011-atomic-mysql-reservation.md).

Supported WordPress is **6.2+** (needed for `wp_cache_flush_runtime`). PHP **8.1+**. WooCommerce is optional.

## Schema

Version 6 adds `{prefix}fuzeo_queue_metrics` and `{prefix}fuzeo_queue_audit`, worker observation columns, and dashboard indexes. Version 5 adds chains, batches, and `cancel_requested` on jobs. Version 4 added uniqueness, idempotency, schedules, occurrence claims, and scheduler heartbeats. Version 3 added `{prefix}fuzeo_queue_attempts`. Version 2 created jobs, workers, and meta. See [ADR-010](adr/010-mysql-queue-schema.md), [ADR-051](adr/051-chain-persistence-and-progression.md), [ADR-054](adr/054-batch-persistence-and-membership.md), and [ADR-075](adr/075-metrics-architecture-and-failure-isolation.md).
