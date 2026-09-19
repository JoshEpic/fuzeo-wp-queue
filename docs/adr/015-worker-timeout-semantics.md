# ADR-015 Worker timeout semantics

## Context

`set_time_limit()` is unreliable under WordPress and extensions.

## Decision

Optional `pcntl_alarm` / `SIGALRM` throws `JobTimeoutException`, which fails the job (no ACK). Without pcntl, timeout is **not** enforced during `handle()`. Document this honestly.

Fatal errors: shutdown function does not ACK. Lease recovery.

## Alternatives

Subprocess with wall-clock kill: deferred. Cooperative checkpoints in handlers: optional later.

## Consequences

Do not claim hard isolation. Production workers should enable `pcntl`.
