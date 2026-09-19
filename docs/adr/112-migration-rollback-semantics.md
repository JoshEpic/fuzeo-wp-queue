# ADR-112 Migration rollback semantics

## Decision

Rollback is offered only when enough source snapshot exists (typically recurring cron/AS schedules). One-off delayed jobs that already exist in Queue are not rolled back. Rollback disables/deletes the Fuzeo schedule, restores the source event, and records `rolled_back`.
