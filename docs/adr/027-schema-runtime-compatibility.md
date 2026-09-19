# ADR-027 Schema and runtime compatibility during upgrades

## Context

Schema v3 adds `fuzeo_queue_attempts`. A 0.2 worker may still be running when 0.3 code and migrations deploy. Multiple plugins may still bundle copies; one Coordinator still owns migrations (`GET_LOCK`).

## Decision

`SchemaOwner::CURRENT_VERSION = 3`. Boot runs migrations 1→2→3. `MySqlDriver::reserve` refuses unless the stored schema version **equals** 3. That blocks a future 0.4 schema from being mutated by a 0.3 worker, and blocks a 0.3 worker from reserving if migrations have not finished (version still 2).

0.2 workers do not have this check. Operators **must restart workers** after upgrading so 0.2 processes do not `fail()` jobs into `failed` instead of retrying. Compatibility series remains `1` (autoload/runtime selection unchanged).

## Alternatives

- Dual-write retry in 0.2 shape: not present in 0.2 code.
- Full deployment generations: Phase 9.

## Consequences

Documented deploy order: update package → request/CLI boot migrates → restart `wp fuzeo-queue work`. Mixed 0.2/0.3 fleets can persist `failed` instead of retry; not silently corrupt SKIP LOCKED reservation, but retry semantics differ.

## Future

Phase 9 can stamp `runtime_version` on jobs at dispatch and refuse older workers more strictly.
