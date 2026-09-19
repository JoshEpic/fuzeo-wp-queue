# ADR-118 External cron worker mode

## Decision

One-shot `wp fuzeo-queue work --once` (and `tick`) is a normal worker with `process_type=cron_cli`, bounded jobs/runtime, `sleep=0`. Overlap is safe via atomic reservation; a TTL runner lock avoids process pileups. Paths are not hard-coded.
