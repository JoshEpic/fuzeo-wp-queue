# ADR-106 Async runtime adapter

## Decision

`AsyncRuntime` is a consumer-facing facade (`dispatch` / `later` / `recurring` / capabilities). It is not `QueueDriver`. Implementations: `FuzeoQueueRuntime`, `ActionSchedulerRuntime`, `UnavailableRuntime`, `FakeAsyncRuntime`.
