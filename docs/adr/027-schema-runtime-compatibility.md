# ADR-027 Schema and runtime compatibility during upgrades

## Context

Schema v3 adds `fuzeo_queue_attempts`. A 0.2 worker may still be running when 0.3 code and migrations deploy. Multiple plugins may still bundle copies; one Coordinator still owns migrations (`GET_LOCK`).

## Decision

`SchemaOwner::CURRENT_VERSION = 5`. Boot runs migrations through 5. `MySqlDriver::reserve` and `SchedulerLoop` refuse unless the stored schema version **equals** 5. That blocks a 0.5 process from mutating v5 rows, and blocks a 0.6 process if migrations have not finished.

Operators **must restart workers and schedulers** after upgrading. Compatibility series remains `1` (autoload/runtime selection unchanged).

## Alternatives

- Dual-write retry in 0.2 shape: not present in 0.2 code.
- Full deployment generations: Phase 9.

## Consequences

Documented deploy order: update package → request/CLI boot migrates → restart `wp fuzeo-queue work` and `wp fuzeo-queue schedule-work`. Mixed fleets can persist `failed` instead of retry and can miss schedule claims.

## Future

Phase 9 can stamp `runtime_version` on jobs at dispatch and refuse older workers more strictly.
