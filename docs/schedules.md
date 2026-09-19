# Recurring schedules

A schedule is a durable definition. The scheduler **creates ordinary queue jobs**. It does not execute handlers.

```text
plugin registers schedule
      ↓
ScheduleBook persists definition + next_run_at
      ↓
wp fuzeo-queue schedule:work  (or schedule:run)
      ↓
claim occurrence → enqueue Job → advance next_run_at
      ↓
normal workers, retries, rate limits, uniqueness
```

## Registration

Register the job type, then the schedule, on `fuzeo_queue_ready`:

```php
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Schedule\CatchUpPolicy;
use Fuzeo\Queue\Schedule\OverlapPolicy;

Queue::schedule()
    ->job('daily-sync', new SyncProducts($connectionId))
    ->dailyAt('02:00')
    ->timezone('America/New_York')
    ->onQueue('imports')
    ->catchUp(CatchUpPolicy::Skip)
    ->overlap(OverlapPolicy::Skip)
    ->save();
```

Expressions:

| Method | Meaning |
| --- | --- |
| `everySeconds($n)` / `everyMinutes($n)` | Fixed UTC interval from `next_run_at` |
| `dailyAt('HH:MM')` | Civil time in the schedule timezone |
| `cron('0 2 * * *')` | Five-field cron (`*`, lists, ranges, `/` steps; `JAN`–`DEC`, `SUN`–`SAT`) |

Cron is a small parser in-package. It is not a full crontab (`@daily`, seconds fields, and `W`/`L`/`#` are unsupported).

Schedule names are 1–64 characters. Internal `schedule_id` is `sha256(origin + name + network + site + scope)`. Two plugins can both use `nightly-sync`.

Reconciliation compares a fingerprint of type, payload, expression, timezone, queue, priority, and policies. When the fingerprint changes, `next_run_at` is recomputed from **now**. Past definitions are not replayed.

## Production scheduler

Preferred:

```bash
wp fuzeo-queue schedule-work
```

WP-CLI maps `scheduleWork` to `schedule-work`. Run it as a long-lived process (systemd, Supervisor, or equivalent). It boots WordPress once, heartbeats, recycles on memory/runtime/job caps, handles SIGTERM, and reconnects the backend.

One-shot (cron, hosting without a process manager, tests):

```bash
wp fuzeo-queue schedule-run
wp fuzeo-queue schedule-run <schedule_id>
```

WP-Cron is **not** the primary scheduler. A future compatibility runner may call `schedule-run`; that is degraded mode.

## Missed runs

Default catch-up is **`skip`**: after downtime, Fuzeo does not backfill missed slots. It advances to the next future occurrence (the currently open slot still dispatches if `next_run_at` is due and the following slot is still in the future).

| Policy | Behavior |
| --- | --- |
| `skip` | No flood after downtime |
| `latest` | One most-recent missed slot (if inside cutoff) |
| `all` | Missed slots up to `schedule_max_catch_up` (default 100) and `schedule_catch_up_cutoff_days` (default 7) |

Truncation records `catch_up_truncated` / `skipped_cutoff` on the schedule. Do not use `all` on high-frequency schedules unless you accept bounded bursts.

## Overlap

`OverlapPolicy::Allow` (default) may enqueue the next occurrence while the previous job is still pending/running.

`OverlapPolicy::Skip` uses unique-job identity `schedule-overlap:{schedule_id}` so a second occurrence is suppressed until the previous job completes or dead-letters.

A dead occurrence does **not** disable the schedule.

## Timezone and DST

Persisted `next_run_at` / `last_run_at` are UTC. The schedule timezone is only for civil-time math.

- **Spring forward:** a local time that does not exist is **skipped**. The next valid matching instant runs.
- **Fall back:** a local time that occurs twice fires **once**, at the earlier offset.

The PHP/WordPress process timezone is never used implicitly.

## Ownership and fail-closed

- Deleted site → schedule blocked (`site_deleted`). Jobs are not redirected to another blog.
- Unregistered job type (plugin deactivated) → blocked (`origin_unavailable`). State is kept. Re-registering and `ScheduleBook::reconcile()` can clear the block.
- Network-scoped schedules dispatch with network context, not blog 1.

## Inspection

```bash
wp fuzeo-queue schedules
wp fuzeo-queue schedules show <id>
wp fuzeo-queue schedules enable <id>
wp fuzeo-queue schedules disable <id>
wp fuzeo-queue status   # includes scheduler count / last heartbeat
```

Payloads are not printed.

## Testing

Use `FrozenClock` (or `Queue::fake()` with a memory driver) and `Queue::runtime()->scheduler()->runDue()`. Do not sleep.

See ADRs 040–043, 049, and 050.
