# ADR-069 Runtime generation and worker recycle

## Context

Plugin activation, deactivation, theme switch, and Queue upgrades leave stale PHP in memory.

## Decision

`RuntimeGeneration` hashes package version, loaded class version, schema version, active plugins, network plugins, and theme. Recorded at worker start. Rechecked every `generation_check_interval` jobs (default 10). Mismatch: finish current job, stop reserving, exit. Candidate metadata version is not treated as the loaded class version.

## Alternatives

Hash every loop tick. Hot-reload plugins. Full Phase 9 deployment generations on every job row.

## Limitations

Does not detect arbitrary file copies without option/theme/package changes until max-jobs/runtime/memory recycle.

## Failure behavior

Old process exits cleanly. Supervisor starts a new process.

## Compatibility

No schema v6. Redis worker hashes may store generation; MySQL worker rows keep existing columns.

## Phase 8/9

Phase 9 can stamp generation on jobs. Phase 8 can show stale workers.
