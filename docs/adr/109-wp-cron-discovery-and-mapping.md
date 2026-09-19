# ADR-109 WP-Cron discovery and mapping

## Decision

`CronInspector` isolates `_get_cron_array()`. `DISABLE_WP_CRON` means automatic spawning is disabled, not that events are absent. Recurrence mapping is descriptor-owned (`intervalSeconds` / cron expression / dailyAt), not inferred from slug alone.
