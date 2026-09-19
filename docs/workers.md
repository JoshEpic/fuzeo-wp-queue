# Workers

Workers are independent PHP CLI processes. They boot WordPress once, then loop.

```bash
wp fuzeo-queue work
wp fuzeo-queue work --queue=high,default --sleep=1 --timeout=60 --lease=90 --memory=128M --max-jobs=500 --max-runtime=3600
```

| Option | Meaning |
| --- | --- |
| `--queue` | Comma-separated names. Left-to-right preference. |
| `--sleep` | Idle poll delay when empty. |
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
```

Payloads are not printed.

## Signals

`pcntl` is **recommended** for production, not required.

- `SIGTERM` / `SIGINT`: stop reserving, finish the current job, mark worker stopped, exit.
- Without `pcntl`, send no signal-based shutdown; recycle with `--max-jobs` / `--max-runtime`.
- `kill -9`: no cleanup. The job lease expires and another worker may run it (**at-least-once**).

## Timeouts

PHP cannot isolate a runaway handler like a separate process supervisor. With `pcntl`, Fuzeo Queue sets `SIGALRM` for the job timeout. Blocking C extensions may ignore it. Fatal errors are not ACK'd; lease recovery is the guarantee.

## Drivers for development

| Driver | Durable | Use |
| --- | --- | --- |
| `Queue::fake()` | No | Plugin tests |
| `memory` | No | In-process experiments |
| `mysql` | Yes | Production and integration tests |
| `unavailable` | No | Fail closed if no `$wpdb` |
