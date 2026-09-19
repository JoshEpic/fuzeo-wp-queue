# ADR-006 Database/schema ownership

## Context

If Bridge, Relay, or the first-loaded plugin “owns” migrations, schema forks and version tables multiply.

## Decision

`fuzeowp/queue` is the schema owner (`SchemaOwner::PACKAGE`). Schema version is stored at **network** level (`fuzeo_queue_schema_version`). A migration lock (`fuzeo_queue_schema_lock`) ensures one runner.

Phase 1 runs a baseline migration (version 1) that records ownership and creates **no** queue tables. `MigrationRunner` is generic; SQL arrives in Phase 2.

## Alternatives considered

- **dbDelta in every consumer:** Duplicate and racy. Rejected.
- **Per-site tables:** Operationally expensive; uniqueness across the network becomes harder. Rejected as the default.

## Consequences

Consumers never ship queue CREATE TABLE statements.

## Future implications

Upgrade sequencing is linear integer versions starting at 1. No down migrations in production.
