# Migrations (operators)

1. Inspect: `wp fuzeo-queue interop status` / admin Interoperability.
2. Preview: `wp fuzeo-queue interop plan <hook> [--source=cron|action-scheduler] [--format=json]` (no mutation).
3. Execute: `wp fuzeo-queue interop migrate <hook> --execute`.
4. Verify Queue schedules/jobs; confirm source is gone.
5. Let leftover AS drain; do not bulk-migrate pending actions unless `--pending` (advanced).
6. Rollback only when history says `rollback_available`: `wp fuzeo-queue interop rollback <migration_id>`.

Recurring algorithm: create Fuzeo schedule **disabled** → record destination → remove source → enable schedule → completed. Unique source identity prevents duplicate destinations. Reconcile after crashes: `wp fuzeo-queue interop reconcile <id>`.

Uninstalling Fuzeo Queue does **not** restore WP-Cron/AS automatically.
