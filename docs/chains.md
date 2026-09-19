# Job chains

A chain is a **strict ordered sequence**. Only the next step is enqueued when the current step is durably completed. Fuzeo Queue remains **at-least-once**: a step may run more than once if ACK is lost. Orchestration does not claim exactly-once.

```php
use Fuzeo\Queue\Queue;

Queue::chain([
    new FetchProducts($connectionId),
    new TransformProducts($connectionId),
    new ImportProducts($connectionId),
])->on('imports')->withOrigin($origin)->dispatch();
```

Steps inherit the chain’s site/network context and origin. Per-step site overrides are not supported in this release.

## Progression

```text
step A ACKs (completed)
  → mark step complete
  → dispatch step B with deterministic job id
  → advance current_step
```

Job identity for step `n` is derived from `chain_id + step_number` (ULID from material). A crash between ACK and dispatch is recovered by `Orchestrator::reconcile()` (worker idle tick or `wp fuzeo-queue reconcile`). Duplicate B rows are suppressed by idempotent enqueue on that job id.

Retryable failures do **not** advance the chain. Only terminal `dead` (stop policy) fails the chain. Subsequent steps stay persisted for inspection.

```bash
wp fuzeo-queue chains
wp fuzeo-queue chains show <id>
wp fuzeo-queue chains cancel <id>
wp fuzeo-queue chains retry <id>   # revive the dead step; uniqueness still applies
```

Cancellation is cooperative: the current step may finish or fail; future steps are not dispatched.

Default failure policy: **stop-on-terminal-failure**. There is no continue/branching policy.

See [ADR-051](adr/051-chain-persistence-and-progression.md)–[ADR-053](adr/053-chain-failure-cancellation-semantics.md).
