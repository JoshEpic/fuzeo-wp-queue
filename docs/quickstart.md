# Quickstart

```bash
composer require fuzeowp/queue:^1.0
```

```php
use Fuzeo\Queue\Jobs\Handler;
use Fuzeo\Queue\Jobs\Job;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Queue;

final class ProcessOrder implements Job
{
    public function __construct(private int $orderId) {}
    public static function type(): string { return 'acme.process_order'; }
    public static function schemaVersion(): int { return 1; }
    public function payload(): array { return ['order_id' => $this->orderId]; }
}

final class ProcessOrderHandler implements Handler
{
    public function handle(Envelope $envelope): void
    {
        $orderId = (int) $envelope->payload['order_id'];
        // load the order by ID; do not persist the live object
    }
}

add_action('fuzeo_queue_ready', function ($runtime): void {
    $origin = new Origin('acme/shop', '1.0.0');
    $runtime->consumers()->register($origin);
    $runtime->jobs()->registerJob(ProcessOrder::class, $origin, ProcessOrderHandler::class);
});

Queue::dispatch(new ProcessOrder(123));
Queue::later('+5 minutes', new ProcessOrder(123));
```

```bash
wp fuzeo-queue work
```

Set `FUZEO_QUEUE_DRIVER=mysql` (WordPress `$wpdb`) or `redis` with `FUZEO_QUEUE_REDIS_DSN`.
