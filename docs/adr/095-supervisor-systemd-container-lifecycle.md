# ADR-095 Supervisor/systemd/container lifecycle

## Context

Operators need copy-pasteable units, not a hosting control plane.

## Decision

Ship docs and examples with placeholders. SIGTERM = finish current job. `stopwaitsecs` / `TimeoutStopSec` / `stop_grace_period` must outlast jobs operators will wait for. Shared code volume/image for web+worker+scheduler.

## Alternatives

Generate configs from PHP. Sidecar operator.

## Races

Old release directories deleted while processes still map them: document prune-after-recycle.

## Operations

See docs/supervisor.md, systemd.md, docker.md.

## Compatibility

Examples are not executed as package tests beyond YAML/INI being present.

## 1.0

Still documentation-only.
