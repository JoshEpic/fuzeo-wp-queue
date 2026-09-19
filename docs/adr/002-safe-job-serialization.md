# ADR-002 Safe job serialization

## Context

PHP `serialize()` can reconstitute arbitrary objects (object injection). WordPress and WooCommerce objects are not durable, not portable, and pin jobs to a single process heap.

## Decision

Persist JSON-compatible values only: strings, ints, floats, bools, null, lists, and string-key maps. Reject objects, closures, resources, non-finite floats, invalid UTF-8, oversized and overly deep documents. Root payload must be a map.

No codec ecosystem in Phase 1. `PayloadSerializer` exists so a future envelope codec can change without rewriting jobs.

## Alternatives considered

- **PHP serialize with an allow-list:** Easy to get wrong; still tempting to store WP objects. Rejected.
- **MessagePack:** Compact, but JSON is universal in WP tooling and inspectable. Deferred.

## Consequences

Developers pass IDs (`order_id`) not `WC_Order`. Dispatch fails closed rather than storing a partial envelope.

## Future implications

If binary payloads are required, add an explicit attachment store. Do not smuggle PHP objects through base64.
