# ADR-119 WordPress compatibility executor

## Decision

A bounded tick (WP-Cron trigger, CLI `compat run`, or admin) runs due schedules then `WorkerLoop` against the real backend. No second queue, no per-job WP-Cron, no recursive HTTP daemon. Fuzeo Queue owns the single `fuzeo_queue_compat_tick` hook.
