# ADR-098 Mixed-version fleet behavior

## Context

Rolling recycle and bundled copies produce mixed processes.

## Decision

Compatible series: mixed package minors may exist briefly; diagnostics show **loaded** version. Incompatible series: refuse. Incompatible schema: old workers refuse reserve. Lua mismatch: Redis workers refuse reserve. Deployment generation groups the fleet in the dashboard.

## Alternatives

Block all mixed fleets. Pretend file replace upgrades RAM.

## Races

Bridge bundles 0.9, Relay 0.8, loaded 0.8: do not run 0.9-only schema.

## Operations

Stale process view and fleet-by-generation.

## Compatibility

Phase 1 coexistence unchanged.

## 1.0

Still at-least-once; mixed fleets are expected during recycle.
