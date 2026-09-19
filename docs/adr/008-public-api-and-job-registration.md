# ADR-008 Public API and job registration

## Context

Laravel-style `SomeJob::dispatch()` is familiar but copies another framework and hides the registry. WordPress developers already think in hooks and explicit registration.

## Decision

- Jobs implement `Job` with `type()`, `schemaVersion()`, `payload()`.
- Plugins register types on `fuzeo_queue_ready`.
- Facade: `Queue::dispatch`, `Queue::on`, `Queue::later`, `Queue::fake`, assertions.
- Core is `QueueManager` + `Dispatcher` for tests without statics.

Handlers are registry entries (`class-string`). Phase 1 does not invoke handlers.

## Alternatives considered

- **Infer type from class name:** Fragile across namespaces and obfuscation. Rejected.
- **No facade:** Purist, worse DX. Facade delegates to Coordinator.

## Consequences

Unregistered jobs cannot be dispatched. Duplicate types fail deterministically.

## Future implications

`Handler` is the worker entry point later. Do not persist class names as the primary key.
