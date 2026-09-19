# ADR-122 Execution capability requirements

## Decision

Two classes only: `standard` and `persistent`. Sources: `RequiresPersistentWorker`, `RequiresExecutionCapabilities` (`persistent_worker` / `long_running`), dispatch `requiresPersistentWorker()`, `persistent_queues`. Do not infer class from timeout alone.
