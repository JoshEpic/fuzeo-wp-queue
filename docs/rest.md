# REST `/fuzeo-queue/v1`

Frozen for 1.0. Breaking changes require `/v2`.

- Auth: WordPress REST cookie/nonce or application passwords plus capability checks. UI hiding is not authorization.
- GET requires `fuzeo_queue_view` / `manage_options` (site) or network view on multisite.
- POST fleet controls require site manage, or **network manage** on multisite.
- Pagination: `limit` capped at 100, `offset` bounded.
- Filters: state, queue, job_type, origin, site_id, job_id, tag. Invalid state → 400.
- Payloads omitted unless explicitly requested and the operator may view payloads. Secrets redacted.
- Redis job `total` may set `total_is_approximate: true`.

Resources: `/overview`, `/queues`, `/jobs`, `/jobs/{id}`, retry/cancel, `/workers`, `/schedules`, `/chains`, `/batches`, `/metrics`, `/diagnostics`, `/health`, `/ready`, `/operations/*`, `/reconcile`.
