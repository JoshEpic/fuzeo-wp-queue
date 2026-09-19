# Action Scheduler

Fuzeo Queue optionally detects Action Scheduler whether it arrived via WooCommerce, a standalone plugin, or another bundle. There is no Composer or WooCommerce dependency.

Public APIs used: `as_enqueue_async_action`, `as_schedule_single_action`, `as_schedule_recurring_action`, `as_unschedule_all_actions`, `as_get_scheduled_actions`, `ActionScheduler::version()`, and store `query_actions(..., 'count')` when present. Target: Action Scheduler **3.x**.

Default adoption: let pending AS actions **drain in place**; send new adapter work to Queue. Recurring declared-compatible actions may migrate to Fuzeo schedules. In-progress actions are never migrated. Failed actions are inspection-only.

Fallback adapter actions use hook `fuzeo_queue_interop_run` and group `fuzeo-interop-{origin}`. At-least-once AS semantics apply while fallback is active (`runtime = action_scheduler`).
