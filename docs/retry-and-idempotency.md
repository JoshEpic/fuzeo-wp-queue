# Retry and idempotency

Fuzeo Queue is **at-least-once**.

## Duplicate side effects

If a handler charges a card and the process dies before ACK, another worker will run the job again. Use the vendor's idempotency key (Stripe, etc.) and/or `Queue::idempotency()->begin()` for a local lease. Local idempotency is not a distributed exactly-once protocol with the outside world.

## Unique is not idempotent

`UniqueJob` / unique keys prevent a second **enqueue** while a claim exists. They do not make a handler safe to run twice. A unique job can still be reserved, crash, and run again.

## Retries

Retryable exceptions back off (optional jitter). Terminal exceptions dead-letter. Poison process crashes consume attempts via lease expiry until the job is dead.

See [retries.md](retries.md), [unique-jobs.md](unique-jobs.md), [idempotency.md](idempotency.md).
