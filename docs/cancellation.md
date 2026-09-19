# Job cancellation

Cancellation is an **operator decision**. It does not kill arbitrary PHP.

| Current state | Outcome |
| --- | --- |
| `pending` | Atomic `cancelled`. The job cannot reserve later. Uniqueness is released. |
| `reserved` | `cancel_requested`. The worker checks before and after the handler. If the handler is already running, stop only if it polls `JobContext::isCancellationRequested()` or throws `JobCancelledException`. |
| terminal | Unchanged |

```php
$result = Queue::cancel($jobId);
// cancelled | cancel_requested | unchanged | not_found
```

Long-running handlers:

```php
final class ImportHandler implements \Fuzeo\Queue\Jobs\ContextualHandler
{
    public function handleContext(\Fuzeo\Queue\Jobs\JobContext $context): void
    {
        foreach ($chunks as $chunk) {
            if ($context->isCancellationRequested()) {
                throw new \Fuzeo\Queue\Exceptions\JobCancelledException('import cancelled');
            }
            $this->import($chunk);
        }
    }
}
```

Cancelled jobs do not auto-retry. Manual revive of cancelled jobs is not supported; dispatch a new job.

```bash
wp fuzeo-queue cancel <job-id> --force
```

`--force` is required. The command explains that executing PHP is not terminated.

Pause/resume of queues, batches, and chains is **not** implemented (deferred; cancellation is the control primitive).

See [ADR-059](adr/059-job-cancellation-semantics.md).
