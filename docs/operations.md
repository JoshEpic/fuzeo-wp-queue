# Operations

Worker and scheduler exits are normal. Supervisors should restart them.

## Recycle triggers

| Reason | Config / signal |
| --- | --- |
| Memory | `worker_memory` / `--memory` |
| Max jobs | `worker_max_jobs` / `--max-jobs` |
| Max runtime | `worker_max_runtime` / `--max-runtime` |
| Runtime / deployment generation | plugin/theme/package/token change (`generation_check_interval`) |
| Restart request | `wp fuzeo-queue restart` |
| Drain | `wp fuzeo-queue drain` |
| Context / transaction | `worker_recycle_on_context_error` |
| SIGTERM / SIGINT | finish current job, then exit |

Suggested starting point: `--max-jobs=500 --max-runtime=3600 --memory=128M`.

## Runtime generation

A SHA-256 of loaded Queue version, schema version, active plugins, network plugins, theme, and optional `FUZEO_QUEUE_DEPLOYMENT_ID`. Workers compare periodically and exit so a new process loads new PHP. The current job finishes on the old code. See [deployments](deployments.md).

Health (is Queue operational?) is not readiness (should this deployment accept work now?). Draining is an intentional operational state, not a generic critical failure.

## Site Health and dashboard

WordPress Site Health reports driver availability, schema, workers vs backlog, Redis eviction policy, and debug fields (versions, generation, worker counts). The Fuzeo Queue admin overview uses the same no-worker and lag signals via `Operations::queueHealth`. Credentials and Redis DSNs are not included.

Queue wait is `reservation time − available_at`. Handler runtime is measured separately. See [ADR-077](adr/077-queue-wait-runtime-definitions.md).

## Capabilities

`fuzeo_queue_view`, `fuzeo_queue_manage`, `fuzeo_queue_retry`, `fuzeo_queue_view_payload`, `fuzeo_queue_view_network`, `fuzeo_queue_manage_network`, with fallbacks `manage_options` / `manage_network_options`. Site admins must not inspect network-wide queue state. CLI is operator access and does not use HTTP capabilities; still pass `--url` when targeting a site.
