# Shared hosting

Persistent CLI workers are the production model. Fuzeo Queue does not pretend every shared host is a suitable worker fleet.

Decision tree:

1. Can the host run a persistent CLI process? → Supervisor/systemd/`wp fuzeo-queue work`.
2. No persistent process, but scheduled CLI (cPanel, Plesk, crontab)? → `wp fuzeo-queue work --once` and `schedule-run`. See [external-cron.md](external-cron.md).
3. No CLI cron? Enable the bounded WordPress compatibility executor **if** the workload is light. See [compatibility-executor.md](compatibility-executor.md).
4. Consumer plugin supports Action Scheduler fallback and Queue is not available or the workload policy requires it → AS for **new** dispatches only.

Limitations in compatibility mode: low throughput, coarse schedule timing, no long-running imports, typically one runner, rate limits are approximate. Do not lower lease/timeout/durability defaults to paper over hosting limits.
