# Interoperability

Fuzeo Queue 1.1 can **discover** Action Scheduler and WP-Cron, **diagnose** coexistence, and **migrate** only workloads a developer has declared compatible.

It does not replace WP-Cron or Action Scheduler globally. It does not monkey-patch `wp_schedule_event()`, `as_enqueue_async_action()`, or related APIs.

Flow: discover → analyze → register a descriptor → preview → execute.

See [action-scheduler.md](action-scheduler.md), [wp-cron.md](wp-cron.md), [runtime-fallback.md](runtime-fallback.md), [migrations.md](migrations.md).
