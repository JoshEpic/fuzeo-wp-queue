# Public WordPress hooks

All project hooks use the `fuzeo_queue_` prefix.

| Hook | When |
| --- | --- |
| `fuzeo_queue_config` | Filter config array (lowest precedence besides defaults) |
| `fuzeo_queue_ready` | Runtime booted; register origins and jobs |
| `fuzeo_queue_worker_started` | Worker process entering the loop |
| `fuzeo_queue_job_preparing` | Site/context about to switch |
| `fuzeo_queue_job_starting` | Handler about to run |
| `fuzeo_queue_before_job` | Alias timing around handler |
| `fuzeo_queue_after_job` | After handler, before/around settlement |
| `fuzeo_queue_job_completed` | Successful ACK path |
| `fuzeo_queue_job_failed` | Failure recorded |
| `fuzeo_queue_job_cancelled` | Cooperative cancel |
| `fuzeo_queue_job_finished` | Terminal for this attempt |
| `fuzeo_queue_runtime_reset` | Between-job WP reset |
| `fuzeo_queue_worker_stopping` | Clean shutdown begun |
| `fuzeo_queue_worker_stopped` | Loop exited |

Do not introduce unprefixed hooks. Internal implementation actions are not a compatibility contract unless listed here.
