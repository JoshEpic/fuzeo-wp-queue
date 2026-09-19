# ADR-020 Retry classification

## Context

Not every `Throwable` should burn retries. Unknown types and programmer errors should stop automatically.

## Decision

`FailureClassifier` is separate from persistence and from `WorkerLoop` catch blocks.

- `RetryableException` / `RetryAfterException`: retry if attempts remain.
- `TerminalException` and subclasses (`UnknownJobException`, `UnsupportedSchemaException`, `UnsupportedEnvelopeException`, `SiteUnavailableException`): dead-letter immediately.
- `TypeError` and other `Error`: terminal.
- Other `Exception`: retryable until `max_attempts`.

Default max attempts is 3.

## Alternatives

- Huge exception taxonomies: rejected.
- Retry everything including `TypeError`: rejected (CPU burn).

## Consequences

Developers opt into retry vs terminal with two exception types plus `RetryAfterException` for delay overrides (e.g. HTTP 429). No distributed rate limiter in Phase 3.

## Compatibility

Phase 2 `fail()` still marks `failed`. Workers now call `FailureStore::settleOutcome()` toward `pending` or `dead`.

## Future

Optional per-job `shouldRetry(Throwable): bool` can be added without replacing the classifier.
