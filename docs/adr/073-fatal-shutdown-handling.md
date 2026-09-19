# ADR-073 Fatal and shutdown handling

## Context

PHP fatals, `exit`, and `wp_die()` terminate the process. Shutdown functions cannot be unregistered.

## Decision

Shutdown hook logs a diagnostic if a reservation is active and does not ACK. Lease recovery remains the source of truth. `wp_die()` under WP-CLI typically exits; treat as process death. Document that handlers must not call `exit`. Shutdown callback accumulation is a recycle reason (max jobs / runtime).

## Alternatives

ACK on shutdown (unsafe). Ignore diagnostics.

## Limitations

SIGKILL and memory fatals may skip the diagnostic.

## Failure behavior

Job returns to pending after lease expiry with attempt consumed.

## Compatibility

Unchanged lease semantics.

## Phase 8/9

Show fatal-with-reservation events if logged.
