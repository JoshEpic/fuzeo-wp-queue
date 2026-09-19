# Fuzeo Queue — Phase 1 Completion Report

**Package:** `fuzeowp/queue` `0.1.0`  
**Date:** 2026-09-19  
**Recommendation:** Phase 1 is complete and ready for Phase 2 (MySQL queue & worker runtime). Do not start Phase 2 in this change set.

## 1. What was built

A Composer library (`Fuzeo\Queue`) that:

- Installs as a PSR-4 package with PHP 8.1+, PHPUnit, PHPStan, and PHPCS.
- Registers bundled copies as **candidates** without booting WordPress on autoload.
- Boots **one** runtime (`Coordinator` + `QueueManager`) after `plugins_loaded`.
- Exposes `Queue::dispatch` / `on` / `later`, job registration, and `Queue::fake()`.
- Persists job identity as a versioned JSON envelope (ULID ids), never PHP `serialize()`.
- Defines driver, reservation, capability, state-machine, schema-ownership, CLI, and admin contracts.
- Defaults production dispatch to `UnavailableDriver` so Phase 1 cannot pretend jobs are durable.
- Implements `MemoryDriver` with enqueue/reserve/ACK/release/fail/extendLease for tests.

## 2. Important architecture decisions

| ADR | Decision |
| --- | --- |
| 001 | Global candidate list + single Coordinator. Coexistence key is `COMPATIBILITY_SERIES`. No PHP-Scoper. PHP autoload still defines one class tree; a newer unused copy is diagnosed, not silently mixed. |
| 002 | JSON maps only; objects/closures/resources rejected. |
| 003 | Envelope v1 + ULID; envelope version ≠ job schema version. |
| 004 | At-least-once; reservation tokens own ACK. |
| 005 | Explicit driver ops + capabilities. Memory + Unavailable only. |
| 006 | `fuzeowp/queue` owns schema; network-level version/lock; baseline migration 1 creates no queue tables. |
| 007 | Envelope carries `network_id`, `site_id`, `scope`. Shared tables, not per-blog schemas. |
| 008 | Stable `type()` registration; facade over injectable `QueueManager`. |
| 009 | Persisted envelope/schema stricter than PHP methods. |

PHP cannot load two `Fuzeo\Queue` class definitions. Highest-version-wins is applied to **candidate metadata**; the loaded class files are whichever Composer autoloader defined them first. That tradeoff is documented rather than hidden with scoping.

## 3. Public API examples

```php
add_action('fuzeo_queue_ready', function ($runtime): void {
    $origin = new \Fuzeo\Queue\Jobs\Origin('acme/shop', '1.0.0');
    $runtime->consumers()->register($origin);
    $runtime->jobs()->registerJob(ProcessOrder::class, $origin);
});

\Fuzeo\Queue\Queue::fake();
\Fuzeo\Queue\Queue::on('fulfillment')->onSite(1, 42)->dispatch(new ProcessOrder(123));
\Fuzeo\Queue\Queue::assertDispatched(ProcessOrder::class);
```

Jobs implement `Job` with `type()`, `schemaVersion()`, and a JSON-safe `payload()` map (`['order_id' => 123]`, never a `WC_Order`).

## 4. Test counts / results

```
PHPUnit 10.5.64
OK (66 tests, 132 assertions)

PHPStan level 8: OK
PHPCS PSR-12: OK
```

Suites: Unit, MultipleBundle, Integration (WordPress stubs), Security.

Critical scenarios:

1. Two compatible copies → one runtime, two consumers, hook/CLI/admin/migration counts = 1.
2. Incompatible series → `IncompatibleRuntimeException`.
3. Object/closure payload → `SerializationException`.
4. Site 42 preserved on the envelope.
5. Duplicate job type → `DuplicateJobTypeException`.
6. Envelope version N+1 → `UnsupportedEnvelopeException`.

WordPress tests use function stubs, not a full WP test install. Network activation of a real plugin is architecturally specified, not executed against Core.

## 5. Files / modules added

**Kernel:** `src/bootstrap.php`, `src/Queue.php`, `Runtime/*`, `Core/*`  
**Jobs:** contract, registry, envelope, factory, state machine, origin, context, redactor  
**Serialization:** `JsonPayloadSerializer` + limits  
**Drivers:** contract, capabilities, reservation, Memory, Unavailable  
**Persistence:** migration runner, baseline, memory + WordPress option lock/repository  
**Config:** precedence repository  
**WordPress:** context resolver, bootstrap, `wp fuzeo-queue` CLI registrar, admin registrar  
**Testing:** `FakeQueue`  
**Docs:** README, guides, threat model, ADR-001–009  
**CI:** `.github/workflows/ci.yml` (PHP 8.1–8.3)

## 6. Known limitations

- No durable storage. Default driver refuses enqueue.
- `MemoryDriver` is process-local.
- WordPress option lock is compare-and-set, not `GET_LOCK()`; Phase 2 should use a real DB lock.
- Schema version 1 does not create queue tables.
- CLI/admin are registration stubs, not product UX.
- Autoload race can leave a newer compatible copy unused (warning diagnostic).
- Integration tests do not boot WordPress Core.

## 7. Deferred work (Phase 2+)

Persistent workers, MySQL reservation algorithm, retries/backoff, dead-letter, schedules, chains/batches, rate limits, heartbeats, metrics, dashboard, Redis, Action Scheduler/WP-Cron adapters, Fuzeo product integrations.

## 8. Deviations from the plan

| Plan | What we did | Why |
| --- | --- | --- |
| Suggested Laravel-like `Queue::assertDispatched` | Kept those names plus type-string and origin/site assertions | Familiar DX without copying the rest of Laravel |
| Highest version always owns classes | Metadata prefers highest version; PHP still first-autoload-wins | Impossible to reload `Fuzeo\Queue\*` without scoping |
| Optional PHP-Scoper | Not used | Would create competing runtimes |
| “Minimal metadata storage if required” | Baseline migration + option keys defined; in-test runner uses memory | Proves ownership without shipping queue DDL |
| WP-CLI name | `wp fuzeo-queue` | Avoids `wp queue` and product `wp fuzeo` collisions |
| Job IDs | ULID, not UUID | Time-sortable, no extra dependency |

## 9. Risks for Phase 2

- Atomic `reserve` / token-conditional ACK in MySQL under concurrency.
- Lease recovery vs. in-flight handlers (at-least-once duplicates).
- Multisite `switch_to_blog` + restoring blog on every job, including fatals.
- Migrating from schema v1 with competing bundled copies during upgrade.
- Payload size vs. MySQL row limits; possible overflow table.
- Aligning plugin Composer versions so the autoload race is boring.

## 10. Phase 1 done?

**Yes.** The package is a clean Composer kernel, autoload does not mutate WordPress, compatible bundles share one runtime, incompatible bundles fail visibly, jobs are registered by stable type, serialization is JSON-only, envelopes are versioned, multisite context exists, driver/reservation contracts are explicit, schema ownership is independent of consumers, and the fake queue is usable without a database.

**Ready for Phase 2: MySQL Queue & Worker Runtime.**
