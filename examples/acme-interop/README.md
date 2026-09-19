# Acme interoperability example

Shows:

1. Queue-first / Action Scheduler-fallback via `Interop::runtime()`.
2. Explicit WP-Cron and Action Scheduler **migration descriptors**.
3. Unknown third-party hooks remaining visible and non-migratable.

Operators still run `wp fuzeo-queue interop plan <hook>` then `migrate --execute`.

Unit tests can use `Interop::fake()` / `FakeAsyncRuntime` without WordPress or Action Scheduler.
