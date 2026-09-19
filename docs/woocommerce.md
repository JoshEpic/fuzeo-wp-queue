# WooCommerce

Fuzeo Queue does not depend on WooCommerce. Typical queue work still touches products, orders, HPOS, and sessions.

## Reload by ID

```php
public function handle(Envelope $envelope): void
{
    $order = wc_get_order((int) $envelope->payload['order_id']);
    if ($order === false) {
        throw new \RuntimeException('Order missing.');
    }
    $order->set_status('completed');
    $order->save();
}
```

Do not persist `WC_Order` in the payload or reuse a PHP object across jobs.

## HPOS

Queue is storage-agnostic. Order IDs remain IDs whether posts or custom tables are used.

## Sessions and CLI

Workers are not storefront requests. Do not initialize customer sessions in handlers unless the job is explicitly about a session, and do not leave session state open across jobs.

## Action Scheduler

WooCommerce may load Action Scheduler. Fuzeo Queue workers do not start AS runners and do not replace AS. Run both if you need both; do not process AS queues inside Queue handlers.
