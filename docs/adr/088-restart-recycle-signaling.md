# ADR-088 Restart/recycle signaling

## Context

Boolean `restart_requested=true` races: a worker that boots after the flag is set would exit immediately.

## Decision

Durable `restart_generation` (ULID). Each process records the value at boot. Recycle only when the durable value **changes**. `wp fuzeo-queue restart` writes a new ULID. Queue never `kill -9`.

## Alternatives

Boolean flag. PID kill. Built-in daemonizer.

## Races

Supervisor starts a worker after R2 is stored: the worker records R2 and stays. SIGTERM plus restart: first recycle reason wins; one exit.

## Operations

CLI/admin/REST request recycle. Process manager restarts OS processes. `--wait` distinguishes old processes exited vs replacements online.

## Compatibility

Stored in `fuzeo_queue_meta` / Redis hash / memory map. Not Redis Lua reservation scripts.

## 1.0

Global restart is required. Targeted per-queue restart remains optional later.
