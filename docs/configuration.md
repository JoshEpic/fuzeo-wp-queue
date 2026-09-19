# Configuration

Precedence, highest first:

1. `Coordinator::boot($candidates, $explicit)` / `bootForTesting($explicit)`
2. Environment variables `FUZEO_QUEUE_*`
3. WordPress constants `FUZEO_QUEUE_*`
4. Filter `fuzeo_queue_config`
5. Package defaults

| Key | Env / constant | Default |
| --- | --- | --- |
| `driver` | `FUZEO_QUEUE_DRIVER` | `unavailable` |
| `default_queue` | `FUZEO_QUEUE_DEFAULT_QUEUE` | `default` |
| `max_payload_bytes` | `FUZEO_QUEUE_MAX_PAYLOAD_BYTES` | `262144` |
| `max_payload_depth` | `FUZEO_QUEUE_MAX_PAYLOAD_DEPTH` | `32` |
| `default_max_attempts` | `FUZEO_QUEUE_DEFAULT_MAX_ATTEMPTS` | `3` |
| `default_timeout_seconds` | `FUZEO_QUEUE_DEFAULT_TIMEOUT_SECONDS` | `60` |
| `redact_payloads` | `FUZEO_QUEUE_REDACT_PAYLOADS` | `true` |
| `lease_seconds` | `FUZEO_QUEUE_LEASE_SECONDS` | `90` |
| `worker_sleep` | `FUZEO_QUEUE_WORKER_SLEEP` | `1` |
| `worker_timeout` | `FUZEO_QUEUE_WORKER_TIMEOUT` | `60` |
| `worker_memory` | `FUZEO_QUEUE_WORKER_MEMORY` | `134217728` (bytes) |
| `worker_max_jobs` | `FUZEO_QUEUE_WORKER_MAX_JOBS` | `0` (unlimited) |
| `worker_max_runtime` | `FUZEO_QUEUE_WORKER_MAX_RUNTIME` | `0` |
| `heartbeat_interval` | `FUZEO_QUEUE_HEARTBEAT_INTERVAL` | `10` |
| `stale_worker_threshold` | `FUZEO_QUEUE_STALE_WORKER_THRESHOLD` | `30` |
| `completed_retention_days` | `FUZEO_QUEUE_COMPLETED_RETENTION_DAYS` | `7` |
| `dead_retention_days` | `FUZEO_QUEUE_DEAD_RETENTION_DAYS` | `30` |
| `default_jitter_percent` | `FUZEO_QUEUE_DEFAULT_JITTER_PERCENT` | `0` |
| `redis_dsn` | `FUZEO_QUEUE_REDIS_DSN` | empty |
| `redis_namespace` | `FUZEO_QUEUE_REDIS_NAMESPACE` | `local` |
| `concurrency` | `FUZEO_QUEUE_CONCURRENCY` | `{}` JSON map |
| `rate_limits` | `FUZEO_QUEUE_RATE_LIMITS` | `[]` JSON list |
| `schedule_claim_lease_seconds` | `FUZEO_QUEUE_SCHEDULE_CLAIM_LEASE_SECONDS` | `30` |
| `schedule_max_catch_up` | `FUZEO_QUEUE_SCHEDULE_MAX_CATCH_UP` | `100` |
| `schedule_catch_up_cutoff_days` | `FUZEO_QUEUE_SCHEDULE_CATCH_UP_CUTOFF_DAYS` | `7` |
| `idempotency_lease_seconds` | `FUZEO_QUEUE_IDEMPOTENCY_LEASE_SECONDS` | `60` |
| `idempotency_retain_seconds` | `FUZEO_QUEUE_IDEMPOTENCY_RETAIN_SECONDS` | `604800` |
| `generation_check_interval` | `FUZEO_QUEUE_GENERATION_CHECK_INTERVAL` | `10` jobs |
| `gc_interval` | `FUZEO_QUEUE_GC_INTERVAL` | `50` jobs |
| `runtime_reset` | `FUZEO_QUEUE_RUNTIME_RESET` | `true` |
| `worker_recycle_on_context_error` | `FUZEO_QUEUE_WORKER_RECYCLE_ON_CONTEXT_ERROR` | `true` |
| `site_health_backlog_critical` | `FUZEO_QUEUE_SITE_HEALTH_BACKLOG_CRITICAL` | `1000` |
| `metrics_enabled` | `FUZEO_QUEUE_METRICS_ENABLED` | `true` |
| `metrics_minute_hours` | `FUZEO_QUEUE_METRICS_MINUTE_HOURS` | `48` |
| `metrics_hour_days` | `FUZEO_QUEUE_METRICS_HOUR_DAYS` | `30` |
| `metrics_day_days` | `FUZEO_QUEUE_METRICS_DAY_DAYS` | `90` |
| `metrics_include_site` | `FUZEO_QUEUE_METRICS_INCLUDE_SITE` | `true` |
| `health_lag_degraded_seconds` | `FUZEO_QUEUE_HEALTH_LAG_DEGRADED_SECONDS` | `60` |
| `health_lag_critical_seconds` | `FUZEO_QUEUE_HEALTH_LAG_CRITICAL_SECONDS` | `300` |
| `health_dead_degraded` | `FUZEO_QUEUE_HEALTH_DEAD_DEGRADED` | `10` |
| `health_dead_critical` | `FUZEO_QUEUE_HEALTH_DEAD_CRITICAL` | `100` |
| `deployment_id` | `FUZEO_QUEUE_DEPLOYMENT_ID` | empty |
| `expected_worker_count` | `FUZEO_QUEUE_EXPECTED_WORKER_COUNT` | `0` |
| `maintenance_lease_seconds` | `FUZEO_QUEUE_MAINTENANCE_LEASE_SECONDS` | `300` |

Drivers: `mysql`, `redis` (PhpRedis), `memory` (tests), `unavailable` (fail closed), plus `Queue::fake()`. Switching drivers does not migrate jobs, schedules, uniqueness claims, or idempotency records. See [Redis](redis.md) and [ADR-050](adr/050-driver-switching-with-scheduler-uniqueness-state.md).
