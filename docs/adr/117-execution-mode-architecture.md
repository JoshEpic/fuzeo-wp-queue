# ADR-117 Execution mode architecture

## Decision

Explicit modes: `persistent`, `cron_cli`, `wordpress_compat`, `none`. Action Scheduler is an interop fallback, not a Queue execution mode. Persistent CLI remains the recommended architecture. Compatibility execution uses the same `QueueDriver` reservation/ACK path.
