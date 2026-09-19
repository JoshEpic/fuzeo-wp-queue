# ADR-071 WooCommerce worker compatibility

## Context

Queue workloads often mutate WooCommerce CRUD objects under HPOS.

## Decision

No WooCommerce Composer dependency. Integration tests skip unless WooCommerce is present. Document reload-by-ID. Do not start Action Scheduler runners. Do not model WC sessions as worker identity.

## Alternatives

Require WooCommerce. Wrap WC objects in the serializer (rejected since Phase 1).

## Limitations

CI may skip WC tests when the plugin is absent.

## Failure behavior

Stale in-memory WC objects are a handler bug; Queue will not serialize them.

## Compatibility

None.

## Phase 8/9

None.
