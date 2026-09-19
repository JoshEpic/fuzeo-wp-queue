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

Phase 1 drivers: `unavailable` (default) and `memory` (tests). `mysql` is Phase 2.
