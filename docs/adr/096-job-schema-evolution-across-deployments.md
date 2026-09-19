# ADR-096 Job schema evolution across deployments

## Context

Delayed jobs, chains, and batches outlive a PHP process generation.

## Decision

Plugin authors dual-read old+new payloads, deploy/recycle, then dispatch new schema. Runtime fails closed on registered schema mismatch. Uniqueness/idempotency persist across deploys. Chains/batches may span generations.

## Alternatives

Pin a chain to one generation. Hot-reload handlers.

## Races

v1 delayed job due after v2 deploy: v2 must understand v1 or dead-letter.

## Operations

docs/job-versioning.md

## Compatibility

Envelope version unchanged (1).

## 1.0

This guidance is part of the public contract for consumers.
