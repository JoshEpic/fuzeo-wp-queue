# Execution modes

Persistent CLI workers remain the recommended architecture. Bounded external-cron and WordPress compatibility execution exist for constrained hosting. They are **not** equivalent to a worker fleet.

| Rank | Mode | How it runs |
| --- | --- | --- |
| BEST | `persistent` | Long-lived `wp fuzeo-queue work` |
| GOOD | `cron_cli` | One-shot CLI from system cron |
| DEGRADED | `wordpress_compat` | Bounded tick inside WordPress |
| FALLBACK | Action Scheduler | Consumer plugin choice when Queue is not used for that dispatch |

Fuzeo Queue stays the queue engine in persistent, cron CLI, and WordPress compatibility modes. Jobs keep durable storage, reservation, leases, attempts, ACK, retry, dead-letter, uniqueness, idempotency, and queue state.

WP-Cron is only a **trigger** for compatibility ticks. It is not a second queue.

## Capabilities (honest)

| | Persistent | Cron CLI | WordPress compat |
| --- | --- | --- | --- |
| Long-running jobs | yes | limited | no |
| Throughput | high | medium | low |
| Latency | low | low (interval) | low / poor |
| Concurrency | yes | limited | very limited |
| Rate limits | yes | yes | limited / imprecise |
| Schedule precision | high | medium (cron grain) | low (WP-Cron grain) |

Mode is inferred from heartbeats (`process_type` + age), with optional `execution_mode` config override for diagnostics only. Compatibility processing backs off while persistent workers are healthy (grace: `compatibility_stale_worker_grace`, default 90s).

## Job execution class

Default: `standard` (compatibility-eligible if `timeout_seconds` ≤ `compatibility_max_job_timeout`, default 60).

Persistent-only:

```php
final class ImportCatalog implements \Fuzeo\Queue\Jobs\Job, \Fuzeo\Queue\Jobs\RequiresPersistentWorker { /* ... */ }

Queue::on('default')->requiresPersistentWorker()->dispatch($job);
```

Config `persistent_queues` marks every job on those queues persistent. Compatibility **skips** those jobs; they stay pending. They are not dead-lettered.

See [external-cron.md](external-cron.md) and [compatibility-executor.md](compatibility-executor.md).
