# Architecture

## Package layout

```
src/
  bootstrap.php          candidate registration only
  Queue.php              developer facade
  Config/
  Contracts/
  Core/                  QueueManager, Dispatcher
  Drivers/               Memory, MySQL, Unavailable
  Retry/                 policies, backoff, jitter, classifier
  Retention/             prune policy
  Persistence/           migrations, PDO/$wpdb connections, GET_LOCK
  Worker/                loop, identity, timeouts, site switching
  WordPress/             context, CLI (`work|status|workers|queues|failed|prune`), admin
```

## Driver operations

See [ADR-005](adr/005-queue-driver-contract.md) and [ADR-011](adr/011-atomic-mysql-reservation.md).

## Schema

Version 3 adds `{prefix}fuzeo_queue_attempts`. Version 2 created jobs, workers, and meta. See [ADR-010](adr/010-mysql-queue-schema.md) and [ADR-022](adr/022-failure-attempt-persistence.md).
