# CLI and admin

## WP-CLI

Command namespace: **`wp fuzeo-queue`**

Reasons:

- `wp queue` collides with other queue plugins
- `wp fuzeo` is reserved for product CLIs (Bridge, Relay, Sync)
- `wp fuzeo-queue` is owned by this package

```php
WP_CLI::add_command('fuzeo-queue', QueueCommand::class);
```

Subcommands: `work`, `schedule-work`, `schedule-run`, `schedules`, `unique`, `idempotency`, `status`, `workers`, `queues`, `jobs`, `failed`, `prune`, `chains`, `batches`, `cancel`, `reconcile`, `health`, `metrics`, `diagnostics`, `restart`, `drain`, `ready`, `migrate`, `interop`.

`diagnostics --format=json` is the paste-safe support snapshot (versions, schema, driver, workers, health; no job payloads). Exit codes for `ready` / `migrate --check` / drain wait are documented in [exit-codes.md](exit-codes.md).

`jobs` lists or shows jobs (`jobs show <id> --payload`). `health` and `metrics` read the operations layer (`--format=json` supported). `prune` also deletes aged metrics/audit rows.

Deployment: `restart` writes a restart generation (workers finish current work and exit; your process manager starts replacements). `drain` stops new reservations. `ready` is automation-friendly (exit 0 only when the deployment may accept work). `migrate` applies package-owned schema migrations (`--check` reports whether work is required). `--format=json` is supported. See [deployments](deployments.md).

`failed` lists dead/failed jobs without payloads. `failed show <id>` prints sanitized metadata and traces. `failed show <id> --payload` includes a redacted payload. `failed retry <id>` revives a dead job (same id, new attempt cycle) and refuses uniqueness conflicts. See [retries](retries.md), [schedules](schedules.md), and [unique-jobs](unique-jobs.md).

`status` includes schema version and scheduler heartbeat count. Redis `unique` list is empty by design (no `KEYS`). There is no destructive idempotency reset command.

`chains` / `batches` list and show orchestration state. `chains retry` revives a dead chain step. `cancel <job-id> --force` is cooperative (does not kill PHP). `prune` also deletes aged terminal chain/batch history. `reconcile` repairs stalled progression after crashes. See [chains](chains.md), [batches](batches.md), and [cancellation](cancellation.md).

## Admin UI

One menu slug: `fuzeo-queue`.

- Single site: `fuzeo_queue_view` / `fuzeo_queue_manage` or `manage_options`
- Network admin: `fuzeo_queue_view_network` / `fuzeo_queue_manage_network` or `manage_network_options`

Pages: Overview, Queues, Jobs, Failed, Workers, Schedules, Chains & Batches, Metrics, Diagnostics, Interoperability. REST namespace `fuzeo-queue/v1`. Payloads are redacted and hidden by default. Retry warns about at-least-once delivery. Running jobs can request cancel; they cannot be killed.
