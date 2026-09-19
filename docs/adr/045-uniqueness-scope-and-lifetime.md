# ADR-045 Uniqueness scope and lifetime

## Context

Raw key `product:123` would collide across plugins, sites, and job types.

## Decision

Hash material: `kind + origin package + scope + network_id + site_id + job_type + normalized key`. Backend namespace/prefix isolates installations.

Kinds: `job`, `occurrence` (scheduler), `overlap` (schedule overlap skip).

Lifetime default: hold until the job **completes or dead-letters**. Optional TTL after release via `_unique.ttl` / `withUniqueTtl()`. TTL is **not** applied to an in-flight job unless the caller set a TTL at acquire time for occurrence claims.

Keys: trim, 1–191 chars, no control characters.

## Alternatives

Queue-only scope: too coarse for multisite. Serialized object hashes: unstable.

## Failure behavior

Empty/oversized keys throw at envelope build, before enqueue.

## Consequences

Same product id in two plugins is two identities. Same key on two job types is two identities.

## Compatibility

Documented as part of 0.5 unique-job contract.
