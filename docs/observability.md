# Observability

- **Metrics** are isolated: a throwing backend must not fail the job.
- **Dashboard** pages are bounded (pagination, Redis scan caps). Redis totals may be approximate.
- **Health** is operational status; **readiness** is whether this deploy should accept work (drain/maintenance/schema).
- **Lag / wait / runtime** are defined in ADR-077. Times are UTC.
- **Site-level metric dimensions** can explode on huge networks; set `metrics_include_site=false` if needed.
- **Diagnostics:** `wp fuzeo-queue diagnostics --format=json` — versions, schema, driver, workers, health, no payloads.

See [operations.md](operations.md) and [cli-and-admin.md](cli-and-admin.md).
