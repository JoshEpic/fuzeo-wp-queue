# ADR-100 Persistence compatibility policy

## Decision

Envelope v1 and Lua 4 remain frozen. Schema 7 is the 1.1 forward migration (`fuzeo_queue_migrations`). Sequential forward migrations only.

## Consequences

Operators can roll back **code** to 0.9 while schema remains 6. They cannot downgrade schema or migrate backends automatically.
