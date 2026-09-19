# WP-Cron

Inspection uses WordPress APIs; `_get_cron_array()` is isolated in `WordPressCronGateway` (internal cron array). `DISABLE_WP_CRON` does not mean events are missing.

Declared-compatible single events migrate to Fuzeo delayed jobs. Recurring events migrate to Fuzeo schedules with explicit interval/cron/daily mapping. Custom slugs such as `every_5_minutes` must be mapped in the descriptor (`intervalSeconds`).

Fuzeo Queue never disables WP-Cron globally. Next-run is preserved in UTC where practical; catch-up policy on migrated schedules is `skip` to avoid double fire at the boundary.
