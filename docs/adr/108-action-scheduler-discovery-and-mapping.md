# ADR-108 Action Scheduler discovery and mapping

## Decision

Detect AS via public functions (`as_enqueue_async_action`, `as_schedule_single_action`, `ActionScheduler::version()`). WooCommerce is not required. Inspection uses `as_get_scheduled_actions` and `ActionScheduler_Store::query_actions(..., 'count')` when present. Direct table SQL is not used. Supported target: Action Scheduler 3.x public API.
