# ADR-104 Package bundling guidance for WordPress plugins

## Decision

Consumers require `fuzeowp/queue:^1.0`, may bundle unscoped Queue, must not PHP-Scoper `Fuzeo\\Queue\\`, and should scope *other* dependencies if needed. First autoloader wins; diagnostics explain a newer unused copy.

## Consequences

One runtime, one schema, one admin, one CLI. Removing the plugin that first booted Queue does not permanently own the runtime.
