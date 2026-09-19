# Operations

Worker and scheduler exits are normal. Supervisors should restart them.

## Recycle triggers

| Reason | Config / signal |
| --- | --- |
| Memory | `worker_memory` / `--memory` |
| Max jobs | `worker_max_jobs` / `--max-jobs` |
| Max runtime | `worker_max_runtime` / `--max-runtime` |
| Runtime generation | plugin/theme/package change (`generation_check_interval`) |
| Context / transaction | `worker_recycle_on_context_error` |
| SIGTERM / SIGINT | finish current job, then exit |

Suggested starting point: `--max-jobs=500 --max-runtime=3600 --memory=128M`.

## Runtime generation

A SHA-256 of loaded Queue version, schema version, active plugins, network plugins, and theme. Workers compare periodically and exit so a new process loads new PHP. The current job finishes on the old code. This is not a full deployment orchestrator (Phase 9).

## Site Health

WordPress Site Health reports driver availability, schema, workers vs backlog, Redis eviction policy, and debug fields (versions, generation, worker counts). Credentials and Redis DSNs are not included.

## Capabilities

`fuzeo_queue_view`, `fuzeo_queue_manage`, `fuzeo_queue_retry`, `fuzeo_queue_view_network`, `fuzeo_queue_manage_network`, with fallbacks `manage_options` / `manage_network_options`. Site admins must not inspect network-wide queue state. CLI is operator access and does not use HTTP capabilities; still pass `--url` when targeting a site.
