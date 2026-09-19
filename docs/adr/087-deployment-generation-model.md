# ADR-087 Deployment generation model

## Context

Phase 7 hashed package, schema, plugins, and theme as `runtime_generation`. Production deploys also replace files without WordPress option changes.

## Decision

Keep `RuntimeGeneration` as the WordPress/plugin fingerprint. Add `DeploymentGeneration` = hash(runtime generation + explicit `deployment_id` + compatibility series + loaded class version + target schema). Do not hash filesystem contents on each check. Cache at boot; recheck on the existing generation interval and idle loop.

## Alternatives

Hash every job. Treat runtime generation as sufficient. Stamp generation on every job row.

## Races

A process started after a token change records the new generation and continues. A process started before it recycles after the current job.

## Operations

Set `FUZEO_QUEUE_DEPLOYMENT_ID` in CI/containers. Plugin activation still changes runtime generation.

## Compatibility

No schema bump. Existing `runtime_generation` worker column stores the deployment fingerprint.

## 1.0

Public config key `deployment_id` remains.
