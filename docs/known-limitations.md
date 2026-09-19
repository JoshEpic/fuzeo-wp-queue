# Known limitations (1.1)

- **At-least-once execution.** Crashes after a side effect and before ACK can duplicate work.
- **Persistent CLI workers** are the production model. Queue does not start replacements.
- **No Redis Cluster.** Keys are not hash-tagged for Cluster. Do not claim Cluster support.
- **No automatic queue backend migration** (MySQL ↔ Redis).
- **First-autoloader-wins** when multiple plugins bundle Queue. Constrain `^1.1` or `^1.0` and do not scope the namespace.
- **No global WP-Cron / Action Scheduler replacement.** Interop is explicit descriptors only; unknown hooks are not migrated.
- **No cross-runtime exactly-once.** AS fallback uses Action Scheduler at-least-once semantics.
- **No automatic failover of in-flight Queue jobs to Action Scheduler.**
- **Static/plugin globals** cannot always be perfectly reset between jobs. Workers recycle on generation/memory/job caps.
- **Redis filtered job lists** are bounded scans; totals may be approximate.
- **File-only deploys** without `FUZEO_QUEUE_DEPLOYMENT_ID` may wait for max-jobs/runtime to recycle.
- **Schema downgrade is unsupported.**
- **Shared hosting without long-running CLI** is not the primary architecture. See [shared-hosting.md](shared-hosting.md).
