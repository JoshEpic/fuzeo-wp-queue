# Jobs and payloads

## Job contract

```php
use Fuzeo\Queue\Jobs\Job;

final class ProcessOrder implements Job
{
    public function __construct(private int $orderId) {}

    public static function type(): string
    {
        return 'acme.process_order';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }

    public function payload(): array
    {
        return ['order_id' => $this->orderId];
    }
}
```

The persisted identity is `type()`, not the PHP class name. Class names may change; types must not.

## Registration

```php
$runtime->jobs()->registerJob(
    ProcessOrder::class,
    new Origin('acme/shop', '1.0.0'),
    ProcessOrderHandler::class, // optional; defaults to the job class
);
```

Duplicate types are allowed only when handler class, schema version, and job class match. Any other conflict throws `DuplicateJobTypeException`. Unknown types throw `UnknownJobException`.

Handlers implement `Handler::handle(Envelope $envelope)` and must be constructible with no required arguments. Unknown types and unsupported payload schema versions dead-letter. After the origin plugin is restored, `wp fuzeo-queue failed retry <id>` can run the same job identity again.

See [retries](retries.md) for policies, backoff, and at-least-once caveats.

## Dispatch

```php
use Fuzeo\Queue\Queue;

Queue::dispatch($job);
Queue::on('fulfillment')->dispatch($job);
Queue::later($timestamp, $job);
Queue::on('imports')->onSite(1, 42)->dispatch($job);
Queue::dispatchResult($job); // accepted vs unique duplicate
Queue::chain([$a, $b, $c])->dispatch();
Queue::batch([$chunk1, $chunk2])->then($finalize)->dispatch();
Queue::cancel($jobId);
```

Delayed jobs: [delayed-jobs.md](delayed-jobs.md). Recurring work: [schedules.md](schedules.md). Chains: [chains.md](chains.md). Batches: [batches.md](batches.md). Cancellation: [cancellation.md](cancellation.md). Implement `UniqueJob` for enqueue dedupe ([unique-jobs.md](unique-jobs.md)). Use `Queue::idempotency()` for logical operations ([idempotency.md](idempotency.md)). These are separate features. Envelope `chain_id`, `batch_id`, and `parent_job_id` are the orchestration links.

With WordPress `$wpdb` present, dispatch uses the MySQL driver. Use `Queue::fake()` in tests.

## Envelope vs job schema

- `envelope_version` — Fuzeo Queue envelope format (currently `1`)
- `schema_version` — your job payload format

Bump the job schema when payload keys or meaning change. Bump the envelope version when envelope fields are removed, renamed, or change meaning. Optional envelope fields can be added without a bump; unknown fields on v1 are ignored at read time only after required fields validate.

Unsupported envelope versions fail with `UnsupportedEnvelopeException`. The runtime will not guess how to execute them.
