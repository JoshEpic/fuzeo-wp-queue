# ADR-097 Rollback and forward-only migrations

## Context

Operators will ask for undo after a bad release.

## Decision

Code-only rollback is restart-with-previous-compatible-code. Schema migrations are forward-only unless a specific migration implements a safe reverse (none in Phase 9). No automatic application rollback. Failed migrate does not bump schema.

## Alternatives

Down migrations for every version. Backup-as-API.

## Races

New envelopes + old handlers: fail closed.

## Operations

docs/rollback.md

## Compatibility

Schema 6 stays.

## 1.0

State reversibility per migration ADR.
