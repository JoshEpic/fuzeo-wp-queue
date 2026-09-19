# ADR-125 Compatibility trigger ownership

## Decision

Fuzeo Queue registers and reconciles `fuzeo_queue_compat_tick`. Consuming plugins must not each register a Queue tick. The hook is internal and Phase 11 migration-ineligible.
