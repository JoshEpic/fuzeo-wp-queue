# ADR-037 Redis namespacing and object-cache isolation

## Context

Many WordPress sites already run Redis object cache on db 0 with keys like `wp:`.

## Decision

Mandatory prefix `fuzeo_queue:{namespace}`. Namespace from config, else 16 hex chars of `sha1(home_url())`. Database index is extra isolation, never the only isolation. Diagnostics show redacted DSN, never credentials.

## Alternatives

Rely on Redis DB numbers: operators still FLUSHDB the wrong index.

## Consequences

Same Redis instance can host cache + queue if prefixes and preferably DBs differ.
