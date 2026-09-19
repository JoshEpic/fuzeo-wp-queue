# WordPress plugin consumer guide

Fuzeo Queue is a Composer dependency inside your plugin, not a plugin you install from wordpress.org.

## 1. Require it

```json
{
  "require": {
    "php": ">=8.1",
    "fuzeowp/queue": "^1.0"
  }
}
```

Commercial/distributed plugins should dump `vendor/` (or an equivalent autoloader) inside the plugin zip. Do not PHP-Scoper `Fuzeo\\Queue\\`. See [bundling.md](bundling.md).

## 2. Autoload

From your plugin bootstrap:

```php
require_once __DIR__ . '/vendor/autoload.php';
```

That only registers a **candidate**. WordPress is not mutated until `plugins_loaded`.

## 3. Register origin and jobs

```php
add_action('fuzeo_queue_ready', function ($runtime): void {
    $origin = new \Fuzeo\Queue\Jobs\Origin('acme/shop', '1.0.0');
    $runtime->consumers()->register($origin);
    $runtime->jobs()->registerJob(ProcessOrder::class, $origin, ProcessOrderHandler::class);
});
```

## 4. Dispatch IDs, not objects

```php
\Fuzeo\Queue\Queue::dispatch(new ProcessOrder($orderId));
```

## 5. Run a worker

```bash
wp fuzeo-queue work --queue=default
```

Shared hosting without SSH cannot run the primary architecture. See [shared-hosting.md](shared-hosting.md).

## 6. Test with the fake

```php
\Fuzeo\Queue\Runtime\Coordinator::bootForTesting();
\Fuzeo\Queue\Queue::register(ProcessOrder::class, new Origin('acme/shop', '1.0.0'));
\Fuzeo\Queue\Queue::fake();
```

Copy the [example plugin](../examples/acme-queue-demo).
