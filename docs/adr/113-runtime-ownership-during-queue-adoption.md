# ADR-113 Runtime ownership during Queue adoption

## Decision

Installing Queue later routes **new** adapter dispatches to Queue. Existing Action Scheduler work is not auto-migrated. Adapter-owned AS actions use hook `fuzeo_queue_interop_run` and group `fuzeo-interop-{origin}`. Unrelated AS actions are never claimed.
