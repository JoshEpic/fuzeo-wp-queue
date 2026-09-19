# ADR-077 Queue wait and runtime definitions

## Context

Operators must distinguish a slow handler from a backed-up queue.

## Decision

**Queue wait** = `reserved_at - available_at` (milliseconds, floored at 0). Intentional delay is not wait. **Runtime** = handler wall time via `hrtime` around `JobExecutor`. **Time to success** = completion clock minus `created_at`. Retry delay is backoff until next `available_at`, not admission waits.

## Alternatives

Wait from `created_at`. Include lock wait in runtime.

## Performance

hrtime is in-process and does not hit storage.

## Security

None.

## Failure behavior

Missing timestamps yield 0.

## Compatibility

Documented in operations docs. State vocabulary unchanged.

## Future

APM traces remain out of scope.
