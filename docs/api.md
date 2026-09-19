# Public API (1.2)

Classification for Fuzeo Queue 1.x. Internal types may change in a minor release.

## PUBLIC STABLE

| Surface | Types / names |
| --- | --- |
| Facade | `Fuzeo\Queue\Queue`, `Queue::interop()`, `Queue::execution()` |
| Interop (1.1) | `Interop\Interop`, `Interop\AsyncRuntime`, `Interop\RuntimePolicy`, `Interop\FakeAsyncRuntime`, descriptors via `Interop::cron` / `Interop::actionScheduler` |
| Execution (1.2) | `Jobs\RequiresPersistentWorker`, `Jobs\RequiresExecutionCapabilities`, `PendingDispatch::requiresPersistentWorker()`, `execution()->tick()` |
| Jobs | `Jobs\Job`, `Jobs\Handler`, `Jobs\ContextualHandler`, `Jobs\JobContext`, `Jobs\Envelope`, `Jobs\Origin`, `Jobs\DispatchOptions`, `Jobs\DispatchResult`, `Jobs\UniqueJob`, `Jobs\JobState`, `Jobs\ExecutionContext`, `Jobs\ExecutionScope` |
| Retry | `Retry\RetryPolicy`, `Retry\Retryable`, `Exceptions\RetryableException`, `Exceptions\TerminalException`, `Exceptions\RetryAfterException` |
| Schedule | `Queue::schedule()`, `Schedule\ScheduleBook` builders |
| Unique / idempotency | `Queue::idempotency()`, unique job contract, `dispatchResult()` |
| Orchestration | `Queue::chain()`, `Queue::batch()`, `Queue::cancel()`, `PendingChain`, `PendingBatch` |
| Testing | `Queue::fake()`, `Testing\FakeQueue` assertions |
| Contracts | `Contracts\Clock`, `Contracts\ExecutionContextResolver` |
| Drivers (implementors) | `Drivers\QueueDriver`, `Drivers\DriverCapabilities`, reservation/failure DTOs |
| Exceptions | `Exceptions\*` hierarchy rooted at `QueueException` |
| Config keys | documented in [configuration.md](configuration.md) |
| Hooks | [hooks.md](hooks.md) (`fuzeo_queue_*`) |
| CLI | `wp fuzeo-queue` |
| REST | `/wp-json/fuzeo-queue/v1` |

`QueueManager` is injectable for tests (`Coordinator::get()` / `bootForTesting()`). Treat new methods as additive; do not subclass it.

## INTERNAL

Namespaces and types not covered by SemVer:

- `Persistence\*`
- `Redis\RedisScripts`, connection adapters
- `Inspection\*` catalogs
- `Metrics\*` stores (except that metrics failure must not fail jobs)
- `Operations\*` stores
- `Deployment\*` persistence
- `Worker\*` loop internals
- Driver classes under `Drivers\MySql`, `Drivers\Redis`, `Drivers\Memory`

These may carry `@internal`. Depending on them is unsupported.

## EXPERIMENTAL

None in 1.0. Features that were experimental in 0.x are either stable or removed.

## DEPRECATED

None carried into 1.0. Pre-1.0 APIs were replaced rather than aliased.

## Envelope freeze (v1)

Required fields: `envelope_version`, `job_id`, `job_type`, `schema_version`, `payload`, `queue`, `priority`, `attempt`, `max_attempts`, `timeout_seconds`, `available_at`, `network_id`, `site_id`, `scope`, `origin`, `correlation_id`, `batch_id`, `chain_id`, `parent_job_id`, `idempotency_key`, `unique_key`, `metadata`, `tags`, `created_at`, `state`.

Optional IDs are ULID-or-null. Tags: at most 32, each ≤ 64 bytes. User metadata is bounded at dispatch.
