# ADR-001 Runtime ownership when multiple plugins bundle Fuzeo Queue

## Context

WordPress installations will contain several plugins that each Composer-require or vendor `fuzeowp/queue`. PHP cannot load two definitions of `Fuzeo\Queue\...`. Competing boots would duplicate hooks, CLI commands, and schema migrations.

## Decision

1. Each copy’s `src/bootstrap.php` (Composer `files` autoload) appends a **candidate** record to `$GLOBALS['fuzeo_queue_kernel']` without booting.
2. On `plugins_loaded` at priority `-1000`, `Coordinator` boots **once**.
3. Coexistence key is `PackageInfo::COMPATIBILITY_SERIES`, not marketing version alone.
4. Compatible candidates may all register jobs and consumer origins on the single `QueueManager`.
5. Incompatible series throw `IncompatibleRuntimeException` and refuse dispatch.
6. PHP-Scoper is not introduced.

## Alternatives considered

- **PHP-Scoper / prefixed copies:** Isolates classes but produces multiple runtimes, duplicate tables, and undebuggable stack traces. Rejected.
- **First-loaded wins with no metadata:** Simple, but incompatible majors would corrupt envelopes silently. Rejected as the sole mechanism.
- **Always highest version via delayed class load:** Ideal, but Composer PSR-4 autoload from the first plugin usually defines classes before others register. We record candidates and warn when a newer compatible copy lost the autoload race.

## Consequences

Plugins should keep Fuzeo Queue versions aligned. A root Composer install is the best layout. Diagnostics are visible; silent mixed runtimes are not allowed.

## Future implications

A Composer plugin or WordPress mu-plugin “version director” could force the newest copy to load first. Envelope and schema versions remain independent of this autoload race.
