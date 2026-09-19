# ADR-017 Worker identity, heartbeat, and stale detection

## Context

PIDs reuse. Heartbeats are not leases.

## Decision

Worker ID is a ULID. Registry stores host, pid, queues, heartbeat, memory, processed count, runtime version.

Stale = heartbeat older than threshold **and** status not stopped. Diagnostics only. Jobs recover solely via lease expiry.

Heartbeat failures do not ACK or fail the in-flight job.

## Alternatives

Use PID as ID: rejected. Heartbeat expiry releases jobs: races with healthy slow handlers.
