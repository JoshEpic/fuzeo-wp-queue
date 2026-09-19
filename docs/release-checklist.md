# 1.0 release checklist

- [ ] PHP 8.1–8.4 unit + static analysis
- [ ] PHPCS PSR-12
- [ ] PHPStan level 8
- [ ] `composer validate --strict`
- [ ] MySQL 8.x and MariaDB 10.6+ SKIP LOCKED suites
- [ ] Redis 6.x and 7.x driver suites
- [ ] WordPress 6.2 and current Core tests
- [ ] Multisite isolation + fleet authorization
- [ ] WooCommerce + HPOS job-by-ID test when WC is present
- [ ] Memory/MySQL/Redis conformance
- [ ] Process crash / poison-job / unique / idempotency races
- [ ] `FUZEO_QUEUE_SCALE_TESTS=1` 10k MySQL + Redis batch/soak (release gate)
- [ ] Security: serialization audit, REST authz, redaction fixture
- [ ] Docs: README, UPGRADING, SECURITY, changelog, known limitations
- [ ] Versions: `PackageInfo::VERSION` 1.2.0, bootstrap candidate 1.2.0, series 1, schema 8, Lua 5, envelope 1
- [ ] Tag `v1.2.0` only after gates are green
- [ ] GitHub Release notes + Packagist `composer require fuzeowp/queue:^1.0`

No commercial release server. No Phase 11 interoperability in the 1.0 tag.
