# Job schema evolution across deployments

Workers may run for a long time. Delayed jobs and chain/batch members routinely execute on a **newer** PHP runtime than the process that dispatched them. Fuzeo Queue remains at-least-once.

## Safe payload evolution

1. New code understands **old and new** payloads (backward reader; optional upcaster in your handler).
2. Deploy and recycle workers.
3. Start dispatching the new schema version.
4. Later remove old readers.

Do not require the entire fleet to flip in one instant.

## Job type stability

Keep `Job::type()` stable. Bump `Job::schemaVersion()` when the payload shape changes. The worker refuses a payload whose schema does not match the **registered** handler schema (`UnsupportedSchemaException` → retry/dead-letter). It will not guess.

## Delayed jobs

A v1 delayed job that becomes due after a v2 deploy must either run under a v2 reader that still understands v1, or fail closed to dead-letter. Silent reinterpretation is a bug in the consuming plugin.

## Schedules

Schedule definitions reconcile as in Phase 5. Restarting the scheduler near a due occurrence must not create duplicate occurrences. Deployment does not duplicate the book.

## Chains and batches

Step 1 may run on generation A and step 2 on generation B. That is normal if persisted blueprints remain compatible. Uniqueness and idempotency records **survive** deploys; they are not reset because the package version changed.

## Compatibility window

Document the oldest payload your handlers still read. Keep that window at least as long as your longest delay + longest drain.
