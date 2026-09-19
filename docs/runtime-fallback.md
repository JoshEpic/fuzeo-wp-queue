# Runtime fallback

Consumers that want Queue-first with Action Scheduler fallback should use `Interop::runtime($origin, RuntimePolicy::PreferQueue)` instead of calling AS APIs directly.

```php
$async = Interop::runtime($origin); // fuzeo_queue | action_scheduler | throws/unavailable
$async->name();
$async->healthy();
$async->supports('batch');
$async->dispatch(new ProcessOrder($id));
```

`require_queue` fails if Queue is not healthy. Fallback does not implement chains, batches, idempotency, or persistent workers.

If Queue is installed later, **new** `dispatch` calls use Queue; existing AS actions stay on AS until they drain. A job already accepted by Queue is never copied to AS during an outage.

Tests: `Interop::fake()` / `FakeAsyncRuntime`.
