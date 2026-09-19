# Persistence compatibility (1.1)

| Artifact | 1.1 version | Rule |
| --- | --- | --- |
| Envelope | 1 | Unchanged from 1.0 |
| Database schema | 7 | Forward only; 7 adds `fuzeo_queue_migrations` |
| Redis data model / Lua | 4 | Unchanged; interop history is MySQL control plane |
| Compatibility series | 1 | Compatible 1.x copies may share one runtime |

**Supported:** 1.0.0 (schema 6) → 1.1.0 (schema 7) then worker recycle. Redis job keys are not rewritten.


## Readable history

Envelope v1 is the only durable envelope. Schema versions 1–7 are sequential. Empty databases apply 1 through 7.

## Upgrade path

**Supported:** 1.0.0 → 1.1.0 (schema 7) then worker recycle. Historical: 0.9.0 → 1.0.0 was schema 6 code-only.

**Reasonable:** any 0.x that can reach schema 6 sequentially, then 1.0 code.

**Unsupported:** schema downgrade, Redis↔MySQL job migration, mixed schema workers reserving jobs.

## Minimum upgrade

1. Deploy 1.0 files.
2. `wp fuzeo-queue migrate --check` then `migrate` if required.
3. Recycle workers (`restart` or process-manager deploy).
4. `wp fuzeo-queue ready`.

Rollback of **code** to 0.9 is possible while schema stays 6. Rollback of schema is not.
