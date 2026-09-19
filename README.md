# Fuzeo Queue

Durable queue infrastructure for WordPress plugin developers.

```bash
composer require fuzeowp/queue
```

Fuzeo Queue is a Composer library, not a WordPress plugin and not a wrapper around WP-Cron or Action Scheduler. It owns its queue architecture.

Phase 4 adds a Redis production driver, driver conformance tests, and fleet concurrency/rate limits. Delivery remains **at-least-once**.

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

// Independent worker:
// wp fuzeo-queue work
```

## Safe payloads

Store IDs and primitive data, not live PHP or WordPress objects.

```php
['order_id' => 123]  // yes
['order' => $wcOrder] // rejected
```

## Delivery semantics

Fuzeo Queue is **at-least-once**. A worker may crash after a side effect and before ACK; after the lease expires another worker will run the job. Handlers must be idempotent.

## Documentation

- [Bootstrapping](docs/bootstrapping.md)
- [Jobs and payloads](docs/jobs.md)
- [Retries and dead letters](docs/retries.md)
- [MySQL driver](docs/mysql.md)
- [Redis driver](docs/redis.md)
- [Workers](docs/workers.md)
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
