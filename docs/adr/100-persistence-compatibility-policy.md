# ADR-100 Persistence compatibility policy

## Decision

Envelope v1, schema 6, Lua 4 are frozen for 1.0. Sequential forward migrations only. No schema 7 to match the package number. 0.9 → 1.0 is a code upgrade plus worker recycle.

## Consequences

Operators can roll back **code** to 0.9 while schema remains 6. They cannot downgrade schema or migrate backends automatically.
