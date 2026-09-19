# Bootstrapping

Requiring `fuzeowp/queue` registers this copy as a **runtime candidate**. It does not start workers, register admin menus, or create database tables by itself.

## WordPress plugin

```php
// plugin.php
require __DIR__ . '/vendor/autoload.php';

use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Queue;

add_action('fuzeo_queue_ready', static function ($runtime): void {
    $origin = new Origin('acme/shop', '1.0.0', __FILE__);
    $runtime->consumers()->register($origin);
    Queue::register(\Acme\Shop\ProcessOrder::class, $origin);
});
```

`fuzeo_queue_ready` fires once, from the winning runtime, after `plugins_loaded` priority `-1000`.

Do not dispatch jobs at file load time. Wait until the runtime is ready, then enqueue from request or hook context.

## Tests and non-WordPress

```php
use Fuzeo\Queue\Runtime\Coordinator;

$runtime = Coordinator::bootForTesting();
```

## What "booted" means

| Question | Answer |
| --- | --- |
| Has Fuzeo Queue already booted? | `Coordinator::isBooted()` |
| Which package version owns the runtime? | `Fuzeo\Queue\Runtime\PackageInfo::VERSION` of the **loaded** classes |
| Is this copy compatible? | Same `PackageInfo::COMPATIBILITY_SERIES` |
| Can this plugin register jobs? | Yes, against `Coordinator::get()->jobs()` after boot |

See [runtime compatibility](runtime-compatibility.md) and [ADR-001](adr/001-runtime-ownership.md).
