# Changelog

## 1.2.0 - 2026-09-19

Compatibility execution modes: persistent workers remain recommended; one-shot CLI/`tick` for external cron; optional bounded WordPress compatibility executor that uses the real Queue backend.

### Fixed

- One-shot workers (`sleep=0`, including compat `tick`) now exit when the queue is empty on Redis. They previously spun forever because `blocking_reserve` skipped the idle exit, which hung CI Redis PHPUnit (especially with a frozen clock).
- Compatibility enable now registers the WP-Cron interval before scheduling, so `wp_schedule_event` succeeds when plugin hooks were not already loaded (WordPress PHPUnit).
- WordPress transaction probes suppress errors on MySQL, which does not provide MariaDB’s `@@in_transaction`.

### Added

- Execution modes `persistent` / `cron_cli` / `wordpress_compat` / `none` with admin, Site Health, CLI, REST, diagnostics
- `wp fuzeo-queue work --once`, `wp fuzeo-queue tick`, `wp fuzeo-queue compat …`
- Schema 8 indexed `execution_class` + `timeout_seconds`; Redis Lua 5 `compat:{queue}` ready set
- `RequiresPersistentWorker` and optional capability-aware reservation filters

### Upgrade

Deploy 1.2, `wp fuzeo-queue migrate --check` (MySQL schema 8), recycle workers. Compatibility series remains 1. Envelope v1 unchanged. See [UPGRADING.md](UPGRADING.md).

## 1.1.0 - 2026-09-19

WordPress interoperability: Action Scheduler and WP-Cron discovery, explicit migration descriptors, Queue-first / Action Scheduler fallback, and an Interoperability admin/CLI/REST surface.

### Added

- `Fuzeo\Queue\Interop` runtime adapter, descriptors, planner, executor, fake testing APIs
- Schema 7 `fuzeo_queue_migrations` (WordPress MySQL control plane, including Redis-driver sites)
- Admin Interoperability view; `wp fuzeo-queue interop …`; REST `/v1/interop/*`

### Upgrade

Deploy 1.1, `wp fuzeo-queue migrate --check` (MySQL schema 7), recycle workers. See [UPGRADING.md](UPGRADING.md). Interop APIs are additive 1.x.

## 1.0.0 - 2026-09-19

Stable open-source release of Fuzeo Queue: durable queues, persistent workers, retries, scheduling, orchestration, and operations for WordPress plugin developers.

### Stability

- Public API classified and frozen for SemVer 1.x
- Envelope v1, schema 6, Lua 4, compatibility series 1 unchanged
- Configuration validation fails closed with actionable errors
- Payload string limits and user tag/metadata limits

### Correctness and performance

- Redis batch member settlement is O(1) in member count (no full-member PHP scan)
- Redis filtered job lists advertise approximate totals when scans are capped

### Security and operations

- Expanded secret redaction fixture coverage
- `wp fuzeo-queue diagnostics` support snapshot without payloads
- Threat model and security policy published for 1.0

### Packaging

- Composer metadata, README, upgrade guide, example plugin, contribution and release docs

### Upgrade

From 0.9.0: deploy code, `wp fuzeo-queue migrate --check`, recycle workers. See [UPGRADING.md](UPGRADING.md).

## 0.9.0

Deployment generations, drain/restart, readiness, Supervisor/systemd/Docker guidance.

## 0.8.0

Metrics, health, REST, admin dashboard, Site Health.

## 0.7.0

WordPress runtime hardening, multisite, WooCommerce/HPOS foundations.

## 0.6.0

Chains, batches, cancellation, reconciliation.

## 0.5.0

Schedules, uniqueness, idempotency, Redis production driver.

## 0.1.0–0.4.0

Kernel, envelopes, MySQL driver, workers, retries, dead letters.
