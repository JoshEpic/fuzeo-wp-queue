# ADR-094 Process manager boundary

## Context

PHP cannot reliably daemonize or supervise itself across hosts.

## Decision

Queue owns recycle/drain/exit reasons and exit codes. Supervisor/systemd/containers own create/restart. No SSH, no autoscaling, no k8s operator, no automatic Supervisor install.

## Alternatives

In-process respawn. Built-in daemon.

## Races

Worker exits, nothing restarts: dashboard `no_process_manager`. Max-runtime remains the backstop if a process never observes restart.

## Operations

Document Supervisor/systemd/Docker separately.

## Compatibility

n/a

## 1.0

Keep the boundary strict.
