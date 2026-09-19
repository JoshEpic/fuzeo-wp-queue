# Fuzeo Queue

Real background job infrastructure for WordPress plugin developers.

Durable queues, persistent workers, retries, scheduling, concurrency, observability, and more.

```bash
composer require fuzeowp/queue:^1.2
```

Fuzeo Queue is a Composer library. It is not a WordPress plugin. It does not globally replace WP-Cron or Action Scheduler; 1.1+ can interoperate with both through explicit adapters and descriptors.

Persistent CLI workers are recommended. Bounded external-cron and WordPress compatibility execution modes are available for constrained hosting.

Delivery is **at-least-once**. A worker may crash after a side effect and before acknowledgement; another worker will run the job after the lease expires. Design handlers accordingly.

## Requirements

| Surface | 1.2 support |
| --- | --- |
| PHP | 8.1, 8.2, 8.3, 8.4 |
| WordPress | 6.2+ (optional until you boot a runtime) |
| MySQL | 8.0.1+ (`FOR UPDATE SKIP LOCKED`) |
| MariaDB | 10.6+ |
| Redis | 6.0+ with PhpRedis (7.x is the primary CI image) |

Requiring the package loads classes. It does not mutate WordPress until a runtime boots on `plugins_loaded`.

## Quick example

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
```

Production workers are persistent CLI processes:

```bash
wp fuzeo-queue work
wp fuzeo-queue schedule-work
```

Unit tests do not need MySQL:

```php
Coordinator::bootForTesting();
Queue::register(ProcessOrder::class, new Origin('acme/shop', '1.0.0'));
Queue::fake();
Queue::dispatch(new ProcessOrder(123));
Queue::assertDispatched(ProcessOrder::class);
```

## What 1.1 includes

- MySQL and Redis durable backends, plus an in-memory driver for tests
- Persistent workers, reservation leases, token-gated ACK
- Retries, backoff, jitter, dead letters, poison-job exhaustion
- Delayed jobs, recurring schedules, uniqueness, idempotency primitives
- Chains, batches, cooperative cancellation, reconciliation
- Metrics, health, REST, admin dashboard, Site Health
- Deployment generations, drain, restart, schema migrations
- Multisite isolation and WooCommerce/HPOS-safe ID-over-object jobs
- Action Scheduler / WP-Cron discovery, explicit migrations, Queue-first fallback runtime
- Bounded external-cron and WordPress compatibility execution for constrained hosting

## What 1.2 is not

- Exactly-once delivery
- A silent WP-Cron or Action Scheduler replacement or interceptor
- Equivalence between WP-Cron ticks and a persistent worker fleet
- Redis Cluster
- A workflow engine, webhook product, or hosting control plane
- A commercial Fuzeo licensing component

## Drivers

Pick **one** production backend. Switching drivers does not migrate jobs.

- **MySQL / MariaDB** — good default when the WordPress database is already operational.
- **Redis** — queue infrastructure, not disposable object cache. Use persistence, `noeviction`, and a dedicated instance or database.
- **Memory / `Queue::fake()`** — tests only.

## Documentation

- [Quickstart](docs/quickstart.md)
- [WordPress plugin guide](docs/plugin-guide.md)
- [Job authoring](docs/job-authoring.md)
- [Execution modes](docs/execution-modes.md)
- [External cron](docs/external-cron.md)
- [Compatibility executor](docs/compatibility-executor.md)
- [Interoperability](docs/interoperability.md)
- [Bundling](docs/bundling.md)
- [SemVer](docs/versioning.md)
- [Persistence](docs/persistence.md)
- [Upgrade from 0.9](UPGRADING.md)
- [Known limitations](docs/known-limitations.md)
- [FAQ](docs/faq.md)
- [Security policy](SECURITY.md)
- [Contributing](CONTRIBUTING.md)

Operations: [workers](docs/workers.md), [MySQL](docs/mysql.md), [Redis](docs/redis.md), [deployments](docs/deployments.md), [Supervisor](docs/supervisor.md), [systemd](docs/systemd.md), [Docker](docs/docker.md), [troubleshooting](docs/troubleshooting.md), [observability](docs/observability.md), [REST](docs/rest.md), [hooks](docs/hooks.md).

## Bundling

When several plugins ship Fuzeo Queue, **the first autoloader wins**. Constrain `fuzeowp/queue` to `^1.0` and do not scope the `Fuzeo\Queue\` namespace. See [bundling](docs/bundling.md).

## Security

Report vulnerabilities privately as described in [SECURITY.md](SECURITY.md). Do not open a public issue for an active vulnerability.

## License

MIT. See [LICENSE](LICENSE).
