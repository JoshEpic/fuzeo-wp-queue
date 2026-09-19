# Testing

The fake queue does not need a WordPress database.

```php
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;

protected function setUp(): void
{
    Coordinator::bootForTesting();
    Queue::register(ProcessOrder::class, new Origin('acme/shop', '1.0.0'));
    Queue::fake();
}

public function test_checkout_dispatches_processing(): void
{
    $this->checkout(orderId: 123);

    Queue::assertDispatched(ProcessOrder::class);
    Queue::assertDispatchedTimes(ProcessOrder::class, 1);
    Queue::assertChainDispatched();
    Queue::assertBatchDispatched();
    Coordinator::get()->fake()->assertBatchSize(2);
    Coordinator::get()->fake()->runUntilIdle();

    Queue::fake()->assertDispatchedOn('fulfillment', ProcessOrder::class);
    Queue::fake()->assertDispatchedWithPayload(ProcessOrder::class, ['order_id' => 123]);
    Queue::fake()->assertDispatchedFrom(ProcessOrder::class, 'acme/shop');
    Queue::fake()->assertDispatchedForSite(ProcessOrder::class, 42);
    Queue::fake()->assertMaxAttempts(ProcessOrder::class, 5);
}
```

Assertions accept a job class or a stable type string such as `acme.process_order`.

Schedules, uniqueness, and idempotency use the same fake/memory driver. Freeze time with `FrozenClock` and call `Queue::runtime()->scheduler()->runDue()` — do not `sleep()` in unit tests.

```php
$clock = new \Fuzeo\Queue\Support\FrozenClock(new DateTimeImmutable('2026-06-01T00:00:00Z'));
Coordinator::bootForTesting(['driver' => 'memory'], clock: $clock);
Queue::register(ProcessOrder::class, new Origin('acme/shop', '1.0.0'));
Queue::schedule()->job('tick', new ProcessOrder(1))->everySeconds(60)->save();
$clock->advance(60);
Queue::runtime()->scheduler()->runDue();
self::assertSame(1, Queue::runtime()->driver()->size('default'));
```

MySQL integration tests need a database:

```bash
docker compose up -d mysql
export FUZEO_QUEUE_TEST_DB_HOST=127.0.0.1
export FUZEO_QUEUE_TEST_DB_USER=root
export FUZEO_QUEUE_TEST_DB_PASS=root
export FUZEO_QUEUE_TEST_DB_NAME=fuzeo_queue_test
vendor/bin/phpunit
```

Redis integration tests:

```bash
docker compose up -d redis
export FUZEO_QUEUE_TEST_REDIS_HOST=127.0.0.1
export FUZEO_QUEUE_TEST_REDIS_PORT=6379
export FUZEO_QUEUE_TEST_REDIS_DB=15
vendor/bin/phpunit --testsuite Redis,Conformance
```

WordPress integration tests (`--testsuite WordPress`) need a downloaded WordPress tree via `bin/install-wp-tests.sh` and `WP_TESTS_DIR`. They are **not** WordPress Core tests; `WP_RUN_CORE_TESTS` stays off. `yoast/phpunit-polyfills` is required because the WP test bootstrap still checks for it. WooCommerce tests skip unless WooCommerce is installed in that suite. Minimum supported WordPress is 6.2.

Phase 8 metrics/operations tests live in `tests/Unit/Phase8*.php`, `tests/MySql/MysqlMetricsTest.php`, and `tests/Redis/RedisMetricsTest.php`. 100k-row history fixtures are not part of the default PHPUnit job.

