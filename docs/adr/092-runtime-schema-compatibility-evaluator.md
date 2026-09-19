# ADR-092 Runtime/schema compatibility evaluator

## Context

Series, schema, Lua, and deployment state were compared in several places.

## Decision

`RuntimeCompatibility` answers can boot / dispatch / reserve, must recycle / migrate. MySQL: exact schema. Redis: Lua version in meta vs `RedisScripts::VERSION`. Memory: no durable schema guard. Loaded class version is distinct from highest bundled candidate metadata.

## Alternatives

Scatter ifs. Loosen exact schema for rolling deploys.

## Races

Old worker vs newer schema: refuse reserve. Candidate 0.9 metadata with 0.8 classes loaded: diagnostics, no claim that 0.9 is running.

## Operations

Diagnostics and `ready` use the same verdict.

## Compatibility

Series 1 vs 2 still fail closed.

## 1.0

Keep a single evaluator.
