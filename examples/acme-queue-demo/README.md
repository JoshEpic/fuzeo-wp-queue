# Acme Queue Demo

Minimal WordPress plugin showing Composer + Fuzeo Queue.

```bash
cd examples/acme-queue-demo
composer require fuzeowp/queue:^1.0
```

Point Composer at the local path while developing this repo:

```json
{
  "repositories": [{ "type": "path", "url": "../.." }],
  "require": { "fuzeowp/queue": "^1.0" }
}
```

1. Load `vendor/autoload.php` from the plugin file (already in `acme-queue-demo.php`).
2. Register origin + job on `fuzeo_queue_ready`.
3. Dispatch `new ProcessOrder(123)`.
4. Run `wp fuzeo-queue work`.
5. Copy `tests/ProcessOrderTest.php` into your plugin test suite.

This example has no Fuzeo commercial dependencies.
