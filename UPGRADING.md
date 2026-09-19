# Upgrading to Fuzeo Queue 1.0

Supported upgrade: **0.9.0 → 1.0.0**.

Earlier 0.x releases should be upgraded to 0.9 first, then to 1.0. Schema migrations are sequential (1→2→3→4→5→6). 1.0 does **not** add schema 7.

## Compatibility series

`COMPATIBILITY_SERIES` remains **1**. SemVer major 1.0 does not start a new bundled-copy epoch. Compatible 1.x copies may share one runtime.

## Envelope and persistence

Envelope **v1** is frozen. Schema **6** is frozen. Lua scripts remain version **4**.

Readable historical envelopes: version 1 only. Older schema versions migrate forward; **schema downgrade is unsupported**.

## Workers

Restart the worker/scheduler fleet after deploying 1.0:

```bash
wp fuzeo-queue migrate --check
wp fuzeo-queue migrate          # no-op if already on schema 6
wp fuzeo-queue restart --wait
wp fuzeo-queue ready
```

Code-only rollback to 0.9 is possible if schema stayed at 6. Do not attempt to reverse DDL.

## Configuration

Invalid configuration now fails at boot instead of failing later in a worker.

New keys (all optional):

| Key | Default | Notes |
| --- | --- | --- |
| `max_payload_string_bytes` | `65536` | Per-string payload cap |
| `max_tags` | `16` | User tags per job (envelope max 32) |
| `max_tag_length` | `64` | Envelope freeze |
| `max_metadata_bytes` | `8192` | User metadata JSON size |
| `max_metadata_key_length` | `64` | User metadata keys |

`default_timeout_seconds` and `worker_timeout` must be **less than** `lease_seconds`.

Invalid Redis DSNs, negative leases, zero concurrency limits, and illegal `deployment_id` characters fail closed.

## Public API

Stable: `Queue`, job/handler contracts, dispatch builders, retry/schedule/unique/idempotency/chain/batch APIs, `Queue::fake()`, `fuzeo_queue_*` hooks, `wp fuzeo-queue`, REST `/fuzeo-queue/v1`, configuration keys.

Internal: `Persistence`, Redis Lua, driver implementations, catalogs, metrics stores. Do not depend on them across minors.

## Redis batches

Member terminal updates no longer load every member into PHP. 10k-member batches are a supported operational size on MySQL and Redis.

## Redis job lists

Filtered Redis job lists remain **bounded** (scan cap 2,000). REST `total_is_approximate` is `true` when the scan stops early. Do not treat those totals as exact.

## CLI

`wp fuzeo-queue diagnostics --format=json` is the support snapshot. It never includes raw job payloads.
