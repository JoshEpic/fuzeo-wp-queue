# ADR-091 Migration sequencing and readiness

## Context

Exact-schema MySQL guards mean mixed schema+runtime is unsafe.

## Decision

Canonical schema deploy: drain → migrate under `GET_LOCK(fuzeo_queue_schema)` plus leased maintenance → start/recycle workers → ready. `up()` then `record()`; failures do not advance version; lock released in `finally`. `migrate --check` reports current/target. Expand/contract mixed-runtime windows are **not** implemented; document for 1.0+ only if a future schema needs a dual-write window.

## Alternatives

Migrate before drain. Dual-write expand/contract now.

## Races

Worker starts during lock: not ready, does not reserve. Two migrate: one owner (`lockedOut`). Scheduler will not dispatch into unsafe schema.

## Operations

CLI `wp fuzeo-queue migrate`. Not a general plugin migration runner.

## Compatibility

Schema remains 6 in Phase 9.

## 1.0

Forward-only unless a migration explicitly documents reverse.
