# WordPress compatibility executor

Degraded mode. Label in admin: **Execution mode: WordPress Compatibility Mode**.

Jobs are processed in bounded WordPress executions because no persistent worker is active. Throughput, latency, scheduling precision, and long-running job support are reduced. This is not full worker mode.

## Enable

Not auto-enabled merely because workers are missing.

```bash
wp fuzeo-queue compat enable
wp fuzeo-queue compat status
wp fuzeo-queue compat run          # one tick (testing)
wp fuzeo-queue compat disable
```

REST (manage capability): `GET /compat/status`, `POST /compat/run`, `GET|POST /compat/settings`. No public executor route. Request params cannot raise bounds.

## Tick

One network-owned WP-Cron hook `fuzeo_queue_compat_tick` (60s). Never one WP-Cron event per Queue job. Multisite: scheduled on the main site only.

```
WP-Cron / CLI / admin
  → runner lock
  → schedule-run (due Fuzeo schedules)
  → reserve/execute up to max_jobs within max_runtime
  → light reconcile
  → exit
```

Defaults: `compatibility_max_runtime` 18s (capped 25, and well below PHP `max_execution_time`), `compatibility_max_jobs` 5. Memory checks match persistent workers. `RuntimeResetter` still runs between jobs in a tick.

If `DISABLE_WP_CRON` is true and no CLI heartbeat is seen, health reports **configured but not being triggered**.

Healthy persistent workers: the tick does not take new work (current job may finish). No job migration.

## Reservation

Executors pass an optional profile on `ReserveRequest` (additive): `execution_class=standard` and `max_timeout_seconds`. MySQL filters indexed `execution_class` + `timeout_seconds`. Redis uses a `compat:{queue}` ready set so incompatible jobs are not popped and released in a loop.

Allowed queues default to `default_queue` unless `compatibility_allowed_queues` is set.

## Chains, batches, schedules, retries

Each chain step or batch member is ordinary Queue work. Large batches drain slowly. A schedule due at 12:00 may dispatch when the next tick runs (for example 12:07). Retries and dead-letter use normal Queue semantics. Poison ticks rely on leases.

## Interop

Queue’s own tick is `not-eligible` for Phase 11 WP-Cron migration. Compatibility never routes through Action Scheduler. Jobs already on Queue are never copied to AS because workers are down.

## Hosting decision

1. Persistent CLI process? → persistent worker.
2. Else scheduled CLI? → external cron.
3. Else enable compatibility if the workload is light.
4. Else consumer AS fallback for workloads that plugin supports.

Do not benchmark compatibility mode as high-throughput production.
