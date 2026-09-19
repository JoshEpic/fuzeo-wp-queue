# Unique jobs

Uniqueness prevents **duplicate queued work**. It does not make handler side effects safe.

```text
Unique job     → one active equivalent job on the queue
Idempotency    → one logical operation even if a job runs more than once
```

Fuzeo Queue remains **at-least-once**. Uniqueness only covers dispatch.

## API

```php
use Fuzeo\Queue\Jobs\Job;
use Fuzeo\Queue\Jobs\UniqueJob;
use Fuzeo\Queue\Queue;

final class SyncProduct implements Job, UniqueJob
{
    public function uniqueKey(): string
    {
        return 'product:' . $this->productId;
    }
}

$result = Queue::dispatchResult(new SyncProduct($id));
if (!$result->accepted) {
    // $result->duplicateOf is the holding job id
}
```

`Queue::dispatch()` still returns an `Envelope` (the new or existing job). Use `dispatchResult()` when you need accepted vs duplicate.

Batch members that implement `UniqueJob` use the same `unique_key` rules. A conflict is stored as member status `unique_conflict` and counts toward `failed_jobs`. **`total_jobs` is never reduced.** Chain-step and batch-member job ids are deterministic ULIDs and are not UniqueStore kinds.

Keys are trimmed, 1–191 characters, no control characters. Do not hash serialized objects.

Optional: `Queue::on('imports')->withUniqueKey('product:123')->withUniqueTtl(3600)->dispatch($job)`.

## Scope

Canonical identity (SHA-256) includes:

```text
kind | origin package | scope | network_id | site_id | job_type | unique_key
```

Installation isolation is the SQL table prefix or Redis namespace. `product:123` in plugin A does not collide with plugin B, another site, or another job type.

## Lifetime

Default: the claim is held while the job is pending, reserved, or retrying. It is **released on completion and on dead-letter**.

Optional `uniqueTtl` / metadata `_unique.ttl` keeps a post-complete (or post-release) TTL so a new dispatch stays blocked for that many seconds.

Retries keep the same job id and uniqueness ownership.

Manual `failed retry` reacquires uniqueness. If another live job already holds the key, retry throws `UniqueConflictException` (CLI prints the conflict). There is no silent override.

## Atomic dispatch

MySQL inserts `fuzeo_queue_unique` in the same transaction as the job row. Redis uses `SET NX` (Lua-checked release). Memory matches for tests.

Duplicate dispatch is not an exception. `DispatchResult::$accepted` is false and `$duplicateOf` is set.

## CLI

```bash
wp fuzeo-queue unique
wp fuzeo-queue unique release <hash> --force
```

Redis `unique` list is empty (keys are not scanned with `KEYS`). Inspect via job metadata instead. Force-release can create duplicate active jobs; it is an expert escape hatch.

See ADRs 044–046.
