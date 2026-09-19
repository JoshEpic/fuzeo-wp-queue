# ADR-107 Queue-first / Action-Scheduler-fallback semantics

## Decision

Policy `prefer_queue` (canonical) selects Fuzeo Queue when the driver is healthy, otherwise Action Scheduler if detected. `require_queue` fails closed. Selection is diagnosable (`fuzeo_queue` / `action_scheduler` / `unavailable`). Fallback never fakes Queue-only features (`supports('batch')` is false on AS).
