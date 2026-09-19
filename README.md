# Fuzeo Queue

Durable queue infrastructure for WordPress plugin developers.

```bash
composer require fuzeowp/queue
```

Fuzeo Queue is a Composer library, not a WordPress plugin and not a wrapper around WP-Cron or Action Scheduler. It owns its queue architecture.

Phase 8 adds observability (metrics, REST `fuzeo-queue/v1`, WordPress admin). Delivery remains **at-least-once**.

## Requirements

- PHP 8.1+
- MySQL 8.0.1+ or MariaDB 10.6+ **or** Redis 6.0+ with PhpRedis for production
- WordPress is optional at the package boundary. Requiring the package loads classes; it does not mutate WordPress until a runtime boots on `plugins_loaded`.

## Quick start

```php
use Fuzeo\Queue\Jobs\Job;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Queue;

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

add_action('fuzeo_queue_ready', function ($runtime): void {
    $origin = new Origin('acme/shop', '1.2.0');
    $runtime->consumers()->register($origin);
    $runtime->jobs()->registerJob(ProcessOrder::class, $origin, ProcessOrderHandler::class);
});

Queue::dispatch(new ProcessOrder(123));
Queue::later('+15 minutes', new ProcessOrder(123));
Queue::chain([new ProcessOrder(1), new ProcessOrder(2)])->dispatch();
Queue::batch([new ProcessOrder(1), new ProcessOrder(2)])->dispatch();

// Independent worker / scheduler:
// wp fuzeo-queue work
// wp fuzeo-queue schedule-work
```

## Safe payloads

Store IDs and primitive data, not live PHP or WordPress objects.

```php
['order_id' => 123]  // yes
['order' => $wcOrder] // rejected
```

## Delivery semantics

Fuzeo Queue is **at-least-once**. A worker may crash after a side effect and before ACK; after the lease expires another worker will run the job. Use [unique jobs](docs/unique-jobs.md) to prevent duplicate enqueue and [idempotency primitives](docs/idempotency.md) (plus vendor idempotency keys) to protect logical effects.

## Documentation

- [Bootstrapping](docs/bootstrapping.md)
- [Jobs and payloads](docs/jobs.md)
- [Delayed jobs](docs/delayed-jobs.md)
- [Recurring schedules](docs/schedules.md)
- [Unique jobs](docs/unique-jobs.md)
- [Idempotency primitives](docs/idempotency.md)
- [Job chains](docs/chains.md)
- [Job batches](docs/batches.md)
- [Cancellation](docs/cancellation.md)
- [Retries and dead letters](docs/retries.md)
- [MySQL driver](docs/mysql.md)
- [Redis driver](docs/redis.md)
- [Workers](docs/workers.md)
- [Long-running workers](docs/long-running-workers.md)
- [Operations](docs/operations.md)
- [WooCommerce](docs/woocommerce.md)
- [Troubleshooting](docs/troubleshooting.md)
- [Testing](docs/testing.md)
- [Configuration](docs/configuration.md)
- [Multisite](docs/multisite.md)
- [Runtime compatibility](docs/runtime-compatibility.md)
- [CLI and admin](docs/cli-and-admin.md)
- [Versioning](docs/versioning.md)
- [Threat model](docs/threat-model.md)
- [Architecture decisions](docs/adr/)

## License

MIT
