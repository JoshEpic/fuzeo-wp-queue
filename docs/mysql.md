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

Schema version 3 adds `{prefix}fuzeo_queue_attempts`. Version 2 created:

- `{prefix}fuzeo_queue_jobs`
- `{prefix}fuzeo_queue_workers`
- `{prefix}fuzeo_queue_meta`

Restart `wp fuzeo-queue work` after upgrading so old workers are not left mutating v3 rows with v0.2 `fail()` semantics.

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
