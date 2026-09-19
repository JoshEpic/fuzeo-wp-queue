# ADR-009 Semantic versioning and persisted-data compatibility

## Context

Third parties will depend on PHP methods *and* on rows that outlive a plugin release. Treating those the same causes either endless majors or silent data breaks.

## Decision

After 1.0, SemVer applies to PHP APIs, hooks, CLI, and config keys. Envelope format and database schema are **stricter**: additive optional envelope fields are allowed; destructive changes require `envelope_version` / schema version plus a package major.

Before 1.0 (`0.1.0`), breaking changes are allowed with release notes. `COMPATIBILITY_SERIES` still gates bundled coexistence.

## Alternatives considered

- **0.x means anything goes including mixed runtimes:** Too dangerous for queued data. Rejected.
- **Never break envelope even in 0.x:** Unrealistic while the kernel is new. We freeze v1 field names now and bump explicitly if we must.

## Consequences

Release checklist includes “can old workers read new rows?” and “can new workers read old rows?”

## Future implications

Horizon-like UI and CLI are public contracts once shipped; Phase 1 only reserves names.
