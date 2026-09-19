# Job batches

A batch is a durable group of jobs that may run in parallel. Membership is stored independently of the live queue so completed/pruned jobs do not drop progress.

```php
Queue::batch([
    new ImportChunk($importId, 1),
    new ImportChunk($importId, 2),
    new ImportChunk($importId, 3),
])
    ->named('import-' . $importId)
    ->collectAll()
    ->then(new FinalizeImport($importId))
    ->catch(new MarkImportFailed($importId))
    ->dispatch();
```

Follow-ups are **registered jobs with JSON payloads**, never closures.

## Creation

1. Header `creating` with `total_jobs` fixed  
2. Members persisted in chunks of 100 with deterministic `batch_id + index` job ids  
3. Jobs materialized  
4. Header `active` only when no members remain `waiting`

A crash mid-create is resumed by reconciliation. Membership is immutable after dispatch (no dynamic add).

## Failure policy

Default **collect-all**: every member runs to a terminal state; the batch is `failed` if any member is dead/unique-conflict.

**fail-fast**: first dead member marks the batch failed and requests cancellation of remaining pending members. Already reserved work is cooperative only.

Unique-job members that lose uniqueness become `unique_conflict` (counts as failed). `total_jobs` is never reduced.

## Progress

Counters update with each member terminal state. `recomputeBatch` rebuilds counts from membership. Completed / failed / cancelled / total are shown in CLI.

```bash
wp fuzeo-queue batches
wp fuzeo-queue batches show <id>
wp fuzeo-queue batches cancel <id>
```

See [ADR-054](adr/054-batch-persistence-and-membership.md)–[ADR-058](adr/058-batch-chain-follow-up-dispatch-ambiguity.md).
