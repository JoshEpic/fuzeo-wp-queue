# MySQL driver

MySQL (or MariaDB) is the first durable production driver.

## Requirements

- MySQL **8.0.1+** or MariaDB **10.6+** (`FOR UPDATE SKIP LOCKED`)
- InnoDB
- PHP `pdo_mysql`

## Activation

When WordPress `$wpdb` is available and `driver` is left at the default `unavailable`, Fuzeo Queue selects **mysql**.

You can set it explicitly:

```php
define('FUZEO_QUEUE_DRIVER', 'mysql');
```

Schema version 5 adds `{prefix}fuzeo_queue_chains`, `{prefix}fuzeo_queue_chain_steps`, `{prefix}fuzeo_queue_batches`, `{prefix}fuzeo_queue_batch_members`, and `cancel_requested` on jobs.

Schema version 4 adds:

- `{prefix}fuzeo_queue_unique`
- `{prefix}fuzeo_queue_idempotency`
- `{prefix}fuzeo_queue_schedules`
- `{prefix}fuzeo_queue_schedule_claims`
- `{prefix}fuzeo_queue_schedulers`

Version 3 added `{prefix}fuzeo_queue_attempts`. Version 2 created jobs, workers, and meta.

Restart `wp fuzeo-queue work` and `wp fuzeo-queue schedule-work` after upgrading so old processes are not left against a newer schema.

`{prefix}` is `$wpdb->base_prefix` so every site in a network shares one queue.

## Payload storage

The full envelope JSON is stored in `MEDIUMTEXT`. Indexed columns exist for reservation (`queue`, `state`, `priority`, `available_at`, `lease_expires_at`, …). Default payload limit remains 256 KiB. Dispatch fails atomically if the envelope cannot be stored.

Queue **identifiers**, not 50 MB import files.

## Reservation

```sql
SELECT ... WHERE queue = ? AND (
  (state = 'pending' AND available_at <= ?)
  OR (state = 'reserved' AND lease_expires_at <= ?)
)
ORDER BY priority DESC, available_at ASC, job_id ASC
LIMIT 1
FOR UPDATE SKIP LOCKED
```

Priority: higher first, then oldest `available_at`, then `job_id`.

Named queues: a worker with `--queue=high,default` polls **high** first, then **default**.
