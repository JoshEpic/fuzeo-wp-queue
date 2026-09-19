# ADR-121 Persistent-worker detection and failover

## Decision

Healthy persistent workers (heartbeat within `stale_worker_threshold`, `process_type=persistent`) are preferred. Compat may start only after `compatibility_stale_worker_grace` (default 90s ≥ stale threshold) with no such heartbeat. Returning workers: compat stops taking work; no ownership migration. WP-Cron compatibility is not auto-enabled.
