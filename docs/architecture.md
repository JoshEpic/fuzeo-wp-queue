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
  Worker/                loop, identity, timeouts, site switching
  WordPress/             context, CLI (`work|schedule-work|schedules|unique|…`), admin
```

## Driver operations

See [ADR-005](adr/005-queue-driver-contract.md) and [ADR-011](adr/011-atomic-mysql-reservation.md).

## Schema

Version 5 adds chains, batches, and `cancel_requested` on jobs. Version 4 added uniqueness, idempotency, schedules, occurrence claims, and scheduler heartbeats. Version 3 added `{prefix}fuzeo_queue_attempts`. Version 2 created jobs, workers, and meta. See [ADR-010](adr/010-mysql-queue-schema.md), [ADR-051](adr/051-chain-persistence-and-progression.md), and [ADR-054](adr/054-batch-persistence-and-membership.md).
