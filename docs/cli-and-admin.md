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

Subcommands: `work`, `schedule-work`, `schedule-run`, `schedules`, `unique`, `idempotency`, `status`, `workers`, `queues`, `failed`, `prune`, `chains`, `batches`, `cancel`, `reconcile`.

`failed` lists dead/failed jobs without payloads. `failed show <id>` prints sanitized metadata and traces. `failed show <id> --payload` includes a redacted payload. `failed retry <id>` revives a dead job (same id, new attempt cycle) and refuses uniqueness conflicts. See [retries](retries.md), [schedules](schedules.md), and [unique-jobs](unique-jobs.md).

`status` includes schema version and scheduler heartbeat count. Redis `unique` list is empty by design (no `KEYS`). There is no destructive idempotency reset command.

`chains` / `batches` list and show orchestration state. `chains retry` revives a dead chain step. `cancel <job-id> --force` is cooperative (does not kill PHP). `prune` also deletes aged terminal chain/batch history. `reconcile` repairs stalled progression after crashes. See [chains](chains.md), [batches](batches.md), and [cancellation](cancellation.md).

## Admin UI

One menu slug: `fuzeo-queue`.

- Single site: capability `manage_options`
- Network admin: capability `manage_network`
- Origin filtering happens inside that UI, not as extra menus per plugin

Phase 1 registers ownership hooks only. No dashboard is rendered.
