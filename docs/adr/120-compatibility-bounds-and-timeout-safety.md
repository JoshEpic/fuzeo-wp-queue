# ADR-120 Compatibility bounds and timeout safety

## Decision

Defaults: 18s runtime (max 25, and PHP `max_execution_time` minus a margin), 5 jobs. Jobs with `timeout_seconds` above `compatibility_max_job_timeout` (default 60) are not reserved in this mode. Historical default-timeout jobs stay `standard` and remain eligible at timeout 60.
