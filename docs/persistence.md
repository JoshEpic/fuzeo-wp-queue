# Persistence compatibility (1.2)

| Artifact | 1.2 version | Rule |
| --- | --- | --- |
| Envelope | 1 | Unchanged from 1.0 |
| Database schema | 8 | Forward only; 8 adds execution_class / timeout_seconds / process_type |
| Redis data model / Lua | 5 | Compat ready set; still reads 1.x job hashes |
| Compatibility series | 1 | Compatible 1.x copies may share one runtime |

**Supported:** 1.1.0 (schema 7) → 1.2.0 (schema 8) then worker recycle. 1.0.0 (schema 6) → 1.1 → 1.2 sequential.

## Readable history

Envelope v1 is the only durable envelope. Schema versions 1–8 are sequential. Empty databases apply 1 through 8.

## Upgrade path

**Supported:** 1.1.0 → 1.2.0 (schema 8, Lua 5) then worker recycle.

**Unsupported:** schema downgrade, Redis↔MySQL job migration, mixed schema workers reserving jobs.

## Minimum upgrade

1. Deploy 1.2 files.
2. `wp fuzeo-queue migrate --check` then `migrate` if required.
3. Recycle workers (`restart` or process-manager deploy).
4. `wp fuzeo-queue ready`.

