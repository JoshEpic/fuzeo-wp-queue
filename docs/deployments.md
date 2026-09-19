# Deployments

Fuzeo Queue is **at-least-once**. A long-running PHP process keeps the classes loaded at start. Replacing files on disk does not upgrade that process.

## Canonical sequences

### Code-only (schema unchanged)

1. Deploy new files (`current` symlink, rsync, container image).
2. Set `FUZEO_QUEUE_DEPLOYMENT_ID` (recommended for immutable deploys).
3. `wp fuzeo-queue restart`
4. Wait until old-generation workers/schedulers exit (`--wait`) and replacements appear.
5. Optional: `wp fuzeo-queue reconcile`
6. `wp fuzeo-queue ready` (exit 0)

Old workers finish the current job, stop reserving, and exit. The **process manager** starts replacements that load new code.

### Schema-changing (exact schema required)

MySQL workers require **exact** `SchemaOwner::CURRENT_VERSION`. Do not loosen that guard.

1. Deploy new files (do not start new workers yet, or let them idle as not-ready).
2. `wp fuzeo-queue drain --wait --timeout=300`
3. `wp fuzeo-queue migrate`
4. `wp fuzeo-queue restart` (or start workers)
5. `wp fuzeo-queue ready`

Short processing interruption is acceptable. Correctness over fake zero-downtime.

**Dispatch:** allowed during drain if storage is compatible. **Refused** during maintenance / half-migrated schema.

## Health vs readiness

| | Health | Readiness |
| --- | --- | --- |
| Question | Is Queue operational? | Should this deployment accept work *now*? |
| Drain | Informational / not critical | Not ready (`draining`) |
| Migration | May be degraded | Not ready (`migration_in_progress` / `schema_mismatch`) |

`wp fuzeo-queue ready` is for automation. Exit 0 = ready; non-zero = not ready.

## Process exit codes

| Code | Meaning |
| --- | --- |
| 0 | Clean recycle, drain, signal, max-jobs/runtime |
| 1 | Startup/config/not-ready (drain, maintenance) |
| 2 | Schema incompatibility / migration required (`migrate --check`) |
| 3 | Backend unavailable |
| 4 | Runtime incompatibility |

## Explicit deployment token

```
FUZEO_QUEUE_DEPLOYMENT_ID=20260919-abc123
```

Included in deployment generation. Changing it recycles workers even when WordPress plugin options are unchanged (container/CI/Envoyer).

File-only rsync without a token or plugin hook is **not** detected until `max-jobs` / `max-runtime` / memory recycle. Document that limitation; set a token or bounded max-runtime.

## Atomic releases

Workers started under `/releases/123` keep using that path until they exit. Do not delete the old release until those processes are gone:

1. Switch `current`
2. Request recycle
3. Confirm old workers gone
4. Prune old releases

## Composer autoload

No hot reload. New processes load new classes.

## Multiple bundled copies

The **loaded class version** is the runtime. Highest candidate metadata is not the running runtime. Queue will not apply a schema the loaded runtime cannot support.

## No process manager

If workers exit and nothing restarts them, Queue cannot daemonize itself. The dashboard warns when no replacement appears.

## Post-deploy

Optional `wp fuzeo-queue reconcile`. No dedicated reconciler daemon.

See [supervisor](supervisor.md), [systemd](systemd.md), [docker](docker.md), [job versioning](job-versioning.md), [rollback](rollback.md).
