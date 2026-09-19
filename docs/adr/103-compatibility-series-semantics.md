# ADR-103 Long-term compatibility-series semantics

## Decision

`COMPATIBILITY_SERIES` remains **1** for Fuzeo Queue 1.x. SemVer major and series diverge only when bundled copies cannot share a runtime/schema/UI. Shipping 1.0.0 does not bump the series.

## Consequences

Plugins requiring `^1.0` can coexist. A future 2.x that cannot share tables must ship series 2 and fail closed next to series 1.
