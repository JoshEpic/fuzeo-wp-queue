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

Drivers: `mysql`, `redis` (PhpRedis), `memory` (tests), `unavailable` (fail closed), plus `Queue::fake()`. Switching drivers does not migrate jobs. See [Redis](redis.md).
