# ADR-061 MySQL/Redis orchestration parity

## Context

Production drivers must show the same chain/batch/cancel outcomes. Redis must not clone SQL tables.

## Decision

`OrchestrationStore` (+ `ChainStore` / `BatchStore`) is backend-neutral. MySQL uses four tables and transactional member updates. Redis uses hashes, sets for incomplete indexes, and Lua where reservation/cancel must be atomic. Memory implements the same contracts for tests (not durable).

Capabilities: `chains`, `batches`, `cancellation` are true on Memory, MySQL, and Redis. Developers should not branch on driver for Phase 6 features.

Conformance tests cover sequential chains, batch follow-up, and pending cancel vs reserve.

## Alternatives

MySQL-only orchestration: Redis queues would lose chains. Dual-write MySQL metadata for Redis jobs: split brain.

## Failure scenarios

Redis eviction still follows ADR-030; orchestration keys use the same namespace. Lua script version remains driver-owned.

## Consequences

Visible semantics match; storage layout differs.

## Compatibility

Redis `schema_version` 5. Mixed Redis/MySQL backends remain unsupported (existing driver-switch ADRs).

## Future

No Redis Cluster in this phase.
