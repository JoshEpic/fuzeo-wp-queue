# Semantic versioning

Fuzeo Queue 1.x follows SemVer. 1.1 adds interoperability APIs without breaking 1.0 contracts.

## Breaking (major)

- Public method signature changes on stable types
- Stable interface changes without a default implementation
- Public exception type removals or hierarchy breaks that callers must catch
- Envelope incompatibility (field removals/renames, unread versions)
- Schema incompatibility without a forward migration
- Redis data-model or Lua script changes that cannot read 1.0 keys
- CLI command removals or flag meaning reversals
- REST `/v1` response breaking changes (use `/v2` instead)
- Configuration key removals
- Public hook removals
- Driver contract changes (`QueueDriver` / capability names)

## Non-breaking (minor / patch)

- New optional config keys
- New optional envelope fields with defaults (still prefer an envelope version bump if meaning is subtle)
- New CLI subcommands
- Additive REST fields
- New hooks
- Internal class refactors
- Performance improvements that preserve semantics

## Compatibility series

`PackageInfo::COMPATIBILITY_SERIES` is **not** SemVer major. It is the bundled-copy runtime epoch. 1.0 remains series **1**. A future series 2 means two copies must not share tables, Redis keys, admin, or CLI.

## Persistence

A 1.x release must not strand 1.0 jobs. See [persistence.md](persistence.md).
