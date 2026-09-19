# Rollback

Fuzeo Queue does **not** automatically roll back applications or databases.

## Code-only rollback

If schema and Lua script versions are unchanged:

1. Deploy the previous compatible code (and previous `FUZEO_QUEUE_DEPLOYMENT_ID` if used).
2. `wp fuzeo-queue restart`
3. Confirm readiness.

## Schema-changing rollback

Forward-only migrations are the default. If schema advanced, **do not** point old code at the new schema. Old workers refuse exact-schema mismatch.

Options:

- Fix-forward (preferred).
- Keep the fleet drained until a compatible release exists.
- Restore a compatible release **only if** it supports the stored schema.

Never encourage unsafe downgrades. Never execute jobs by guessing payload semantics. Envelopes created by newer job schemas must fail closed on old handlers.

## Failed deploy recovery

If new workers will not boot after a successful migration:

1. Leave Queue not-ready / drained.
2. Fix-forward the release.
3. Do not run `migrate` again expecting automatic undo.

See [deployments](deployments.md) and [ADR-097](adr/097-rollback-and-forward-only-migrations.md).
