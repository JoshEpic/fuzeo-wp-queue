# Delayed jobs

Fuzeo Queue honors `available_at` on every production driver. A delayed job is still one ordinary job. Workers cannot reserve it until that instant.

## API

```php
use Fuzeo\Queue\Queue;

Queue::later('+15 minutes', new SendReminder($userId));
Queue::later(new DateTimeImmutable('+1 hour'), new SendReminder($userId));
Queue::later(1_800, new SendReminder($userId)); // unix seconds

Queue::on('reminders')->later('+15 minutes')->dispatch(new SendReminder($userId));
Queue::on('reminders')->delay(900)->dispatch(new SendReminder($userId));
```

`Queue::later()` and `PendingDispatch::later()` accept:

- `DateTimeInterface` (converted to UTC)
- unix timestamp (`int`, 1970–2100 UTC)
- relative strings starting with `+` or `-` (applied to the injectable clock)
- other parseable datetime strings

`PendingDispatch::delay(int $seconds)` is a non-negative offset from now.

Invalid, empty, impossible, or out-of-range values throw `InvalidDelayException`.

## Past timestamps

A delay that is already in the past is **immediately eligible**. The timestamp is stored as given (normalized to UTC). It is not rejected and it is not shifted forward.

## Internals

All persisted `available_at` values are UTC. MySQL uses an indexed column. Redis uses a delayed sorted set promoted on reserve. Memory uses the same eligibility check for tests.

See [ADR-039](adr/039-delayed-job-public-semantics.md).
