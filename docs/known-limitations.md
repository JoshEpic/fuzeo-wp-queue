# Known limitations (1.0)

- **At-least-once execution.** Crashes after a side effect and before ACK can duplicate work.
- **Persistent CLI workers** are the production model. Queue does not start replacements.
- **No Redis Cluster.** Keys are not hash-tagged for Cluster. Do not claim Cluster support.
- **No automatic queue backend migration** (MySQL ↔ Redis).
- **First-autoloader-wins** when multiple plugins bundle Queue. Constrain `^1.0` and do not scope the namespace.
- **No WP-Cron / Action Scheduler interoperability** in 1.0 (future adoption layer).
- **Static/plugin globals** cannot always be perfectly reset between jobs. Workers recycle on generation/memory/job caps.
- **Redis filtered job lists** are bounded scans; totals may be approximate.
- **File-only deploys** without `FUZEO_QUEUE_DEPLOYMENT_ID` may wait for max-jobs/runtime to recycle.
- **Schema downgrade is unsupported.**
- **Shared hosting without long-running CLI** is not the primary architecture. See [shared-hosting.md](shared-hosting.md).
