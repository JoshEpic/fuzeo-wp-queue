# Architecture

## Package layout

```
src/
  bootstrap.php          candidate registration only
  Queue.php              developer facade
  Config/
  Contracts/
  Core/                  QueueManager, Dispatcher
  Drivers/               contracts + Memory + Unavailable
  Exceptions/
  Jobs/                  Job, Envelope, Registry, state machine
  Persistence/           migration ownership
  Runtime/               Coordinator, PackageInfo
  Serialization/
  Support/
  Testing/
  WordPress/             context, CLI/admin registrars
```

## Job states

Stored: `pending`, `reserved`, `completed`, `failed`, `cancelled`.

`running` is derived: reserved + unexpired lease.

Transitions: see `JobStateMachine`.

## Driver operations

See [ADR-005](adr/005-queue-driver-contract.md). `MemoryDriver` implements the contract for tests. Production MySQL is Phase 2.

## Schema

`fuzeowp/queue` owns schema versioning (`fuzeo_queue_schema_version` at network level). Baseline migration version 1 records ownership and creates no queue tables.
