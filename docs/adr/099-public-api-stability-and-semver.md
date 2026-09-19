# ADR-099 Public API stability and SemVer

## Decision

1.0 classifies APIs as PUBLIC STABLE / INTERNAL / EXPERIMENTAL / DEPRECATED (none deprecated). SemVer applies to the stable surface. Persistence is stricter than PHP methods.

## Consequences

Internal namespaces may change in minors. Envelope/schema/CLI/REST/hooks/config/driver contracts listed in `docs/api.md` and `docs/versioning.md` require a major if broken.
