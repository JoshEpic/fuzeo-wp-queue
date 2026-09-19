# Job authoring

- **`type()`** is the durable identity (`vendor.action`). Never persist class names as the primary key.
- **`schemaVersion()`** is the payload meaning. Bump when fields change meaning; run old and new handlers or refuse old payloads explicitly.
- **Payloads** are JSON maps of primitives. Store IDs, not live WooCommerce/WordPress objects.
- **Retries / timeout** via `Retryable` or dispatch options. Timeout must stay below the reservation lease.
- **Uniqueness** prevents duplicate *enqueue*. **Idempotency** protects *effects*. They are not the same.
- **Cancellation** is cooperative. Check `JobContext` when doing long work.
- **Tags / metadata** are operator hints with size limits (default 16 tags, 8 KiB metadata).
- **Site context** is captured at dispatch. Network jobs need network-scoped handlers available on the worker.
