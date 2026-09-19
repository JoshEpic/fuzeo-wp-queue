# Shared hosting

Persistent CLI workers are the production model. Fuzeo Queue 1.0 does not pretend every shared host is a suitable worker fleet.

If you only have WP-Cron:

- You may dispatch jobs into MySQL storage.
- You do **not** have the primary execution architecture.
- Degraded/cron-driven execution and Action Scheduler interoperability are **not** 1.0 features.

Do not lower lease/timeout/durability defaults to paper over hosting limits. Use a VPS, container, or host that allows Supervisor/systemd and a long-running `wp fuzeo-queue work`.
