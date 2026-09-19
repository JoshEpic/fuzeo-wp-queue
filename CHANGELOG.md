# Changelog

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
