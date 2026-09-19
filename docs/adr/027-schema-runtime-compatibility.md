# ADR-027 Schema and runtime compatibility during upgrades

## Context

Schema v3 adds `fuzeo_queue_attempts`. A 0.2 worker may still be running when 0.3 code and migrations deploy. Multiple plugins may still bundle copies; one Coordinator still owns migrations (`GET_LOCK`).

## Decision

`SchemaOwner::CURRENT_VERSION = 8`. Boot runs migrations through 8. `MySqlDriver::reserve` and `SchedulerLoop` refuse unless the stored schema version **equals** 8. Operators **must recycle workers and schedulers** after upgrading (`wp fuzeo-queue restart` / drain-then-migrate). Compatibility series remains `1`. Lua scripts are version 5.

Phase 9 adds deployment generations, restart/drain tokens, and a compatibility evaluator. Exact schema is not loosened.

Phase 7 adds in-process **runtime generation** (plugins/theme/package) so workers recycle without a schema bump. Phase 9 adds deployment tokens and durable restart generations. Operators **must recycle workers and schedulers** after upgrading. Compatibility series remains `1`.

## Alternatives

- Dual-write retry in 0.2 shape: not present in 0.2 code.
- Full deployment generations: Phase 9.

## Consequences

Documented deploy order: update package → request/CLI boot migrates → restart `wp fuzeo-queue work` and `wp fuzeo-queue schedule-work`. Mixed fleets can persist `failed` instead of retry and can miss schedule claims.

## Future

Phase 9 can stamp `runtime_version` on jobs at dispatch and refuse older workers more strictly.
