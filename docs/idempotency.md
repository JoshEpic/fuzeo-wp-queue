# Idempotency primitives

Fuzeo Queue cannot make arbitrary handler code idempotent. It provides a **store** so handlers can coordinate a logical operation.

This is not exactly-once processing.

```text
begin() returns owned
      ↓
external side effect succeeds
      ↓
process dies before complete()
```

The store still shows `started`. Fuzeo cannot know whether the vendor call happened. Prefer the **same key** on the external API when the vendor supports idempotency keys.

## API

```php
use Fuzeo\Queue\Queue;

$idempotency = Queue::idempotency();
$begin = $idempotency->begin('invoice-charge:' . $invoiceId);

if (!$begin->owned) {
    if ($begin->status === \Fuzeo\Queue\Idempotency\IdempotencyStatus::Completed) {
        return; // already done; $begin->result may hold small JSON metadata
    }
    // another worker holds started
    return;
}

try {
    // side effect — send $invoiceId (or the Fuzeo key) as the vendor idempotency key
    $idempotency->complete((string) $begin->ownerToken, ['charge_id' => $chargeId]);
} catch (\Throwable $e) {
    $idempotency->fail((string) $begin->ownerToken);
    throw $e;
}
```

There is no closure wrapper. Closures are not durable.

States: `started`, `completed`. `fail()` deletes a started claim so a later `begin()` can proceed. Completed records are retained (`idempotency_retain_seconds`, default 7 days) then expire.

## Ownership

`begin()` is atomic. One owner token wins. `complete()`, `fail()`, and `heartbeat()` require that token. A stale owner cannot mutate a newer claim.

Started records have a lease (`idempotency_lease_seconds`, default 60s). Heartbeat extends it. Expiry allows another `begin()`. **Expiry does not prove the side effect did not happen.**

Keys are scoped by execution context (site/network). Result metadata must be small and JSON-safe.

## CLI

`wp fuzeo-queue idempotency` explains that destructive reset is not provided. Clearing completed keys can duplicate charges.

See ADRs 047–048.
