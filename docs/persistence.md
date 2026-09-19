# Persistence compatibility (1.0)

| Artifact | 1.0 version | 1.x rule |
| --- | --- | --- |
| Envelope | 1 | Readable; additive optional fields only without a version bump |
| Job payload schema | per `job::schemaVersion()` | Workers refuse unknown/unregistered types; payload meaning is the job author's contract |
| Database schema | 6 | Forward migrations only; 1.0 did not add schema 7 |
| Redis data model | prefix `fuzeo_queue:{namespace}:` | Key grammar is frozen; Cluster unsupported |
| Lua scripts | 4 | Mismatch fails closed; no gratuitous bump in 1.0 |

## Readable history

Envelope v1 is the only durable envelope. Schema versions 1–6 are reachable by sequential `up()` migrations (`IF NOT EXISTS` / recorded version). Direct jump from an empty database to 6 applies 1 through 6 in order.

## Upgrade path

**Supported:** 0.9.0 (schema 6) → 1.0.0 (schema 6, code-only) then worker recycle.

**Reasonable:** any 0.x that can reach schema 6 sequentially, then 1.0 code.

**Unsupported:** schema downgrade, Redis↔MySQL job migration, mixed schema workers reserving jobs.

## Minimum upgrade

1. Deploy 1.0 files.
2. `wp fuzeo-queue migrate --check` then `migrate` if required.
3. Recycle workers (`restart` or process-manager deploy).
4. `wp fuzeo-queue ready`.

Rollback of **code** to 0.9 is possible while schema stays 6. Rollback of schema is not.
