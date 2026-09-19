# ADR-025 Retry transaction boundaries

## Context

Half-applied “insert history then release” strands jobs or loses diagnostics.

## Decision

MySQL `settleOutcome` is one transaction: token-gated job update + attempt insert, then commit. Stale tokens affect 0 rows and roll back. If the connection dies, the worker must not treat the retry as durable; leases recover the job. Ambiguous commit (request sent, result unknown) is the same class of problem as ambiguous ACK (ADR-016): inspect durable state after reconnect; never claim certainty.

SIGTERM is checked at loop boundaries. Settlement runs to completion inside `process()` before the next `shouldExit()`.

## Alternatives

- Autocommit two statements: rejected.
- Queue a second “retry job”: rejected.

## Consequences

Failure history can be missing after a crash during the handler (no PHP finally). That is documented, not hidden.

## Compatibility

`FailureStore` implementers must provide equivalent atomicity or document that they do not.

## Future

Outbox-style dual writes remain out of scope.
