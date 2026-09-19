# ADR-074 Worker soak and recycle policy

## Context

Workers must survive thousands of WordPress jobs without silent corruption.

## Decision

Recycle is healthy. Default memory 128MB, optional max jobs/runtime, generation checks every 10 jobs, `gc_collect_cycles` every 50 jobs. Soak: 10,000 lightweight memory-driver jobs in CI. Intentional leak handlers recycle via memory threshold; a replacement worker drains the queue. Scheduler uses the same lifecycle.

## Alternatives

Never recycle. GC after every job.

## Limitations

10k WordPress Core soak is environment-dependent; CI uses the memory driver plus a smaller real-WP suite.

## Failure behavior

Stuck jobs still recovered by leases.

## Compatibility

CLI flags unchanged.

## Phase 8/9

Metrics belong to Phase 8; this phase only keeps health snapshots in process.
