# ADR-030 Redis durability requirements

## Context

WordPress often uses Redis as an evictable object cache.

## Decision

Document two modes. Durable queue Redis needs persistence (AOF/RDB as ops policy) and `maxmemory-policy` that will not evict job keys (`noeviction` or volatile-* with TTLs only on ephemeral keys). Health reports the policy and warns on allkeys-* eviction. Fuzeo Queue does not change Redis configuration.

## Alternatives

Refuse to boot on unsafe policy: too hostile for shared hosts. Silent operation: hides data loss.

## Consequences

Administrators can lose jobs if they point Queue at cache Redis. That is an infrastructure failure, not a Queue ACK.
