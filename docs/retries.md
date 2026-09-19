# Retries, failures, and dead letters

Fuzeo Queue is **at-least-once**. Retries do not prove that a previous handler run had no side effects. If a handler charges a card and the worker dies before ACK, another worker will run the job again. Use remote idempotency keys, durable application checks, and upserts. Queue does not invent exactly-once semantics.

## What counts as an attempt

An attempt **starts when a worker successfully reserves the job**. The stored `attempt` counter increments atomically with reservation (and with lease-steal recovery). It never decreases except on explicit **manual retry**, which starts a new execution cycle and increments `_replay` in metadata.

Looking at a job without reserving it does not consume an attempt.

A crash after reservation still consumed that attempt. That is how poison jobs that fatal the process eventually dead-letter instead of rebooting workers forever.

## Defaults

- Maximum attempts: **3**
- Backoff: **exponential** from 30 seconds (30, 60, 120, … capped at 1 hour)
- Jitter: **off** (0%). Enable per job to spread synchronized retries.

Override at dispatch:

```php
use Fuzeo\Queue\Retry\RetryPolicy;
use Fuzeo\Queue\Retry\SequenceBackoff;

Queue::on('imports')
    ->withMaxAttempts(5)
    ->withRetryPolicy(new RetryPolicy(5, new SequenceBackoff([10, 30, 120, 600]), 10))
    ->dispatch($job);
```

Jobs may implement `Fuzeo\Queue\Retry\Retryable` and return a `RetryPolicy`. Policy is copied into envelope metadata at dispatch (declarative JSON, not closures).

## Retryable vs terminal

Throw `RetryableException` (or `RetryAfterException::after(120)` for a 429-style delay override) when the work might succeed later.

Throw `TerminalException` when retrying cannot help (malformed payload, missing domain entity).

Defaults:

- Most `Exception` subclasses are retryable until attempts are exhausted.
- `TypeError`, other `Error` subclasses, unknown job types, unsupported payload schema versions, unsupported envelopes, and missing/deleted sites are **terminal**.
- Unknown job types dead-letter with enough envelope data to **manually retry** after the origin plugin is restored.

## States

```
pending → reserved → completed
reserved → pending          (retryable failure; available_at is the backoff time)
reserved → dead             (terminal or attempts exhausted)
pending → dead              (poison leftover: attempt already at max)
dead|failed → pending       (operator manual retry)
```

`failed` remains for the raw `QueueDriver::fail()` API from Phase 2. New worker outcomes use `dead`. Neither is auto-reserved.

## Poison jobs

Uncatchable fatals leave no failure row. The lease expires; the next reservation increments `attempt`. When `attempt >= max_attempts`, the job is dead-lettered without executing again.

## Inspection

```bash
wp fuzeo-queue failed
wp fuzeo-queue failed show <job-id>
wp fuzeo-queue failed show <job-id> --payload   # redacted payload
wp fuzeo-queue failed retry <job-id>
wp fuzeo-queue prune --batch-size=500
wp fuzeo-queue status   # includes pending, reserved, completed, dead, failed, retrying
```

Do not put secrets in payloads. Traces store class/file basename/line/function only, truncated.

## Retention

Defaults: completed jobs 7 days, dead/failed 30 days. `prune` deletes in bounded batches and removes attempt history for those jobs first. Configure `completed_retention_days` and `dead_retention_days`.

## Ambiguous persistence

If the database is gone while recording a failure, the worker does **not** claim a durable retry. The lease is the safety net. A dropped connection after `COMMIT` is indistinguishable from before `COMMIT`; the runtime must not invent certainty.
