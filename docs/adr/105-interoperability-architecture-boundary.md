# ADR-105 Interoperability architecture boundary

## Decision

Interop lives in `Fuzeo\Queue\Interop\`, outside queue transport. Fuzeo Queue never globally intercepts `wp_schedule_*` or Action Scheduler APIs. Workloads move only after discover → analyze → explicit descriptor → migrate.

## Consequences

Unknown hooks are visible and non-migratable. QueueManager has an additive `interop()` accessor without AS/WP-Cron conditionals in the driver path.
