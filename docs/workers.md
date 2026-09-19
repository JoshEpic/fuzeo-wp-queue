# Workers

Workers are independent PHP CLI processes. They boot WordPress once, then loop.

```bash
wp fuzeo-queue work
wp fuzeo-queue work --queue=high,default --sleep=1 --timeout=60 --lease=90 --memory=128M --max-jobs=500 --max-runtime=3600
```

| Option | Meaning |
| --- | --- |
`--queue` left-to-right preference is the same for MySQL and Redis. Redis workers `BLPOP` only on the **last** listed queue after polling the others.

Worker registry is stored in the active backend (MySQL tables or Redis hashes), not a separate control plane.
| `--sleep` | Idle poll delay when empty. `0` means exit when the queue is empty (non-blocking drivers). |
| `--timeout` | Soft job timeout (`pcntl_alarm` when available). |
| `--lease` | Reservation visibility timeout. Should exceed `--timeout`. |
| `--memory` | Graceful recycle after RSS threshold. |
| `--max-jobs` | Graceful recycle after N jobs. |
| `--max-runtime` | Graceful recycle after N seconds (finishes current job). |

## Diagnostics

```bash
wp fuzeo-queue status
wp fuzeo-queue workers
wp fuzeo-queue queues
wp fuzeo-queue failed
wp fuzeo-queue prune
wp fuzeo-queue restart
wp fuzeo-queue drain
wp fuzeo-queue ready
wp fuzeo-queue migrate --check
```

Payloads are not printed.

## Signals

`pcntl` is **recommended** for production, not required.

- `SIGTERM` / `SIGINT`: stop reserving, finish the current job, mark worker stopped, exit.
- Without `pcntl`, send no signal-based shutdown; recycle with `--max-jobs` / `--max-runtime`.
- `kill -9`: no cleanup. The job lease expires and another worker may run it (**at-least-once**).

## Timeouts

PHP cannot isolate a runaway handler like a separate process supervisor. With `pcntl`, Fuzeo Queue sets `SIGALRM` for the job timeout. Blocking C extensions may ignore it. Fatal errors are not ACK'd; lease recovery is the guarantee.

Recycle is normal. See [operations](operations.md) and [long-running workers](long-running-workers.md). Runtime generation (plugins/theme/package) also stops the process after the current job.

Hooks: `fuzeo_queue_worker_started`, `fuzeo_queue_job_preparing`, `fuzeo_queue_job_starting`, `fuzeo_queue_before_job`, `fuzeo_queue_job_completed`, `fuzeo_queue_after_job`, `fuzeo_queue_job_failed`, `fuzeo_queue_job_cancelled`, `fuzeo_queue_job_finished`, `fuzeo_queue_runtime_reset`, `fuzeo_queue_worker_stopping`, `fuzeo_queue_worker_stopped`.

## Drivers for development

| Driver | Durable | Use |
| --- | --- | --- |
| `Queue::fake()` | No | Plugin tests |
| `memory` | No | In-process experiments |
| `mysql` | Yes | Production and integration tests |
| `unavailable` | No | Fail closed if no `$wpdb` |
