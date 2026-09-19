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

    Queue::fake()->assertDispatchedOn('fulfillment', ProcessOrder::class);
    Queue::fake()->assertDispatchedWithPayload(ProcessOrder::class, ['order_id' => 123]);
    Queue::fake()->assertDispatchedFrom(ProcessOrder::class, 'acme/shop');
    Queue::fake()->assertDispatchedForSite(ProcessOrder::class, 42);
}
```

Assertions accept a job class or a stable type string such as `acme.process_order`.

MySQL integration tests need a database:

```bash
docker compose up -d mysql
export FUZEO_QUEUE_TEST_DB_HOST=127.0.0.1
export FUZEO_QUEUE_TEST_DB_USER=root
export FUZEO_QUEUE_TEST_DB_PASS=root
export FUZEO_QUEUE_TEST_DB_NAME=fuzeo_queue_test
vendor/bin/phpunit
```

