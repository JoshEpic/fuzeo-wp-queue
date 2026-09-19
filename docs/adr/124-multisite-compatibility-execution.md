# ADR-124 Multisite compatibility execution

## Decision

One network-owned trigger on the main site. The executor uses normal site-context switching per job. No per-site worker loops and no cross-site shortcuts.
