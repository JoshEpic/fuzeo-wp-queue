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
  Persistence/           migrations, PDO/$wpdb connections, GET_LOCK
  Worker/                loop, identity, timeouts, site switching
  WordPress/             context, CLI (`work|status|workers|queues`), admin
```

## Driver operations

See [ADR-005](adr/005-queue-driver-contract.md) and [ADR-011](adr/011-atomic-mysql-reservation.md).

## Schema

Version 2 creates `{prefix}fuzeo_queue_jobs`, `_workers`, and `_meta`. See [ADR-010](adr/010-mysql-queue-schema.md).
