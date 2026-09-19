# Redis driver

Fuzeo Queue can persist work in Redis instead of MySQL. The developer API is unchanged: `Queue::dispatch()` still goes through the driver abstraction.

## Client

Production Redis uses **PhpRedis (`ext-redis`)**. Predis is not bundled. If the extension is missing, boot fails with an install hint rather than silently falling back to MySQL.

Minimum Redis version: **6.0** (Lua `cjson`, `EVALSHA`, `ZRANGEBYSCORE`).

## Configuration

Prefer environment or PHP constants, not WordPress options:

| Key | Env / constant | Notes |
| --- | --- | --- |
| `driver` | `FUZEO_QUEUE_DRIVER=redis` | |
| `redis_dsn` | `FUZEO_QUEUE_REDIS_DSN` | `redis://` or `rediss://` (TLS) |
| `redis_namespace` | `FUZEO_QUEUE_REDIS_NAMESPACE` | Installation id; default `local`, or a hash of `home_url()` |

DSN passwords never appear in `wp fuzeo-queue status`.

## Namespacing

All keys are `fuzeo_queue:{namespace}:...`. Do not share this prefix with a WordPress object cache. Using the same Redis **server** is fine; using the same **keyspace** is not. A dedicated database index is recommended in addition to the namespace, not instead of it.

## Durability

Queue cannot override Redis `maxmemory-policy`. For durable queues use `noeviction` (or `volatile-*` if you only expire keys that have TTLs). AOF or RDB persistence is an infrastructure choice. Volatile cache Redis will lose jobs on eviction or restart; health checks warn when the policy is allkeys-lru/lfu/random.

## Semantics

Reservation, leases, attempt-on-reserve, stale ACK, retry, dead-letter, revive, and pruning match MySQL. Delayed jobs live in a per-queue sorted set and are promoted during `reserve`. Blocking workers `BLPOP` a short wakeup list after a miss.

Ready-queue order is `priority DESC, available_at ASC`. Ties are Redis member order (ULID), not a second `job_id ASC` column.

## Concurrency and rate limits

```php
// config
'concurrency' => ['imports' => 4],
'rate_limits' => [['key' => 'imports', 'per_minute' => 100]],
```

```php
Queue::on('default')->withRateLimit(RateLimit::perMinute(100)->withKey('vendor-api'))->dispatch($job);
```

Admission happens **before** attempt increment. Throttling delays or skips reservation; it does not burn retries.

Workers fail at startup if concurrency or rate limits are configured on a driver that does not advertise `queue_concurrency` / `atomic_rate_limits`.

## Switching drivers

Jobs are not migrated. Drain the previous backend first. Coordinator emits a `driver_switch` diagnostic when the configured driver changes.
