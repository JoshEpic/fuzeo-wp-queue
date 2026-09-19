# ADR-093 Worker/scheduler startup readiness

## Context

A process must not reserve if the deployment cannot accept work.

## Decision

Before reserving, workers evaluate compatibility. `canBoot=false` exits with a small exit-code set. Drain/maintenance: stay (or recycle if drain started after boot) but do not reserve. Schedulers skip `runDue` when not allowed to reserve. `wp fuzeo-queue ready` is fleet/deployment readiness (automation).

## Alternatives

Reserve first. Exit-loop during every drain including boot-during-drain (restart storms).

## Races

Migration in progress: idle, no reserve. Schema already newer than loaded: exit 2.

## Operations

Container/CLI ready checks.

## Compatibility

Does not change at-least-once ACK/lease recovery.

## 1.0

Stable reason codes listed in `ReadinessReason`.
