# ADR-126 Queue / Action Scheduler fallback interaction

## Decision

Dispatch ownership is sticky: a Queue job is never copied to Action Scheduler because workers are down. Compatibility may process it. Runtime adapters expose `executionMode()` / `supportsLongRunningJobs()` / `supportsPersistentWorkers()` on capabilities. AS remains consumer fallback when Queue is not selected at dispatch.
