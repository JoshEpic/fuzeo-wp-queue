# Contributing

## Setup

```bash
composer install
composer validate --strict
vendor/bin/phpcs
vendor/bin/phpstan analyse --memory-limit=512M
vendor/bin/phpunit --testsuite Unit
```

## Tests

| Suite | Needs |
| --- | --- |
| Unit, Security, MultipleBundle, Conformance (Memory) | nothing extra |
| MySQL / Conformance MySQL | `FUZEO_QUEUE_TEST_DB_*` and SKIP LOCKED |
| Redis | `ext-redis` and `FUZEO_QUEUE_TEST_REDIS_*` |
| Process | POSIX + MySQL or Redis as documented per test |
| WordPress | `WP_TESTS_DIR` from `bin/install-wp-tests.sh` |
| WooCommerce | WordPress suite + WooCommerce installed |
| Scale (`10k` batch/soak) | `FUZEO_QUEUE_SCALE_TESTS=1` |

Do not use unbounded `sleep()` in unit tests. Prefer `FrozenClock`.

## Style

PHPCS **PSR-12** for `src/` and most tests. WordPress-facing admin/REST/CLI must keep capability checks, nonces, and `esc_*` escaping even though the package is not WPCS-formatted.

PHPStan level 8. Do not add generics that make the public API unreadable.

## Architecture decisions

Behavior that is durable, security-sensitive, or public-API should get an ADR under `docs/adr/` when the decision is new. Do not add ADRs just to hit a number.

## Pull requests

Use the PR template. Call out:

- tests added
- BC impact
- schema / envelope / Lua impact
- driver parity
- docs

## Security

See [SECURITY.md](SECURITY.md). Do not discuss active vulnerabilities in public PRs.
