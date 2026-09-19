# External cron workers

Use this when the host can run scheduled WP-CLI but not a persistent process.

```cron
* * * * * cd /path/to/wordpress && wp fuzeo-queue schedule-run
* * * * * cd /path/to/wordpress && wp fuzeo-queue work --once --max-jobs=25 --max-runtime=50 --sleep=0
```

Or one combined invocation:

```cron
* * * * * cd /path/to/wordpress && wp fuzeo-queue tick
```

Replace:

- working directory (`cd`) with this WordPress root
- `wp` with the WP-CLI binary (or ` /usr/bin/php /path/to/wp-cli.phar `)
- PHP binary if the panel requires an explicit path

`--once` sets bounded one-shot defaults and records `process_type=cron_cli`. This is still a normal Fuzeo worker: same driver, reservation, ACK, retry.

Print examples (does not install crontab):

```bash
wp fuzeo-queue compat cron-example
```

## Overlap

Two cron lines may overlap. Atomic reservation prevents duplicate **job** ownership. An optional runner lock (`fuzeo-compat-tick` token + TTL) skips extra **processes** so you do not pile CLI boots. The lock is not job ownership; it expires after a crash.

## Logs

Redirect stdout/stderr in the panel (`>> /path/to/fuzeo-queue-cron.log 2>&1`) if the host allows it. Fuzeo does not ship a log daemon.

Persistent Supervisor/systemd remains the recommended production path. See [workers.md](workers.md) and [shared-hosting.md](shared-hosting.md).
