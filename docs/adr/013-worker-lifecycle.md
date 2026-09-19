# ADR-013 Worker lifecycle and process model

## Context

HTTP request workers cannot outlive the request. Persistent CLI processes can leak memory.

## Decision

`wp fuzeo-queue work` boots WordPress once, registers a worker row, loops (heartbeat → reserve → execute → ACK), recycles on memory/max-jobs/max-runtime after finishing the current job.

`pcntl` is recommended. SIGTERM finishes the current job. SIGKILL relies on leases.

Hooks: `fuzeo_queue_before_job`, `fuzeo_queue_after_job`, `fuzeo_queue_job_failed`, `fuzeo_queue_worker_stopping`, plus Phase 7 lifecycle hooks in [ADR-063](063-long-running-wordpress-runtime-model.md).

## Alternatives

Subprocess per job: stronger isolation, much slower. WP-Cron loop: not independent of HTTP.

## Consequences

Object cache and static state can linger. Consumers should reset on `after_job`. We do not flush the entire object cache after every job.
