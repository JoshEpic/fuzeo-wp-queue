# Upgrading Fuzeo Queue

## 1.0.0 → 1.1.0

Additive interoperability APIs. Compatibility series remains **1**. Envelope v1 and Lua 4 are unchanged.

MySQL schema **7** adds `fuzeo_queue_migrations` (control plane). Redis job Lua stays 4; Redis sites with `$wpdb` still get the MySQL interop table.

```bash
wp fuzeo-queue migrate --check
wp fuzeo-queue migrate
wp fuzeo-queue restart --wait
wp fuzeo-queue ready
```

1.1 does not auto-migrate WP-Cron or Action Scheduler. Register descriptors, preview, then execute.

## 0.9.0 → 1.0.0

Supported upgrade to 1.0: **0.9.0 → 1.0.0**. Schema 1→6 sequential. See git history of 1.0 notes if you are still on 0.9.
