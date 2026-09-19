# ADR-034 Rate limiting algorithm and semantics

## Context

API quotas must delay work, not drop it.

## Decision

One algorithm: **token bucket**. Burst = capacity. `RateLimit::perSecond(n)` / `perMinute(n)` / `withKey()`. Persist policy in envelope `metadata._rate` or config `rate_limits`. Redis hash `rate:{key}` `{tokens, ts}` updated in reserve Lua. MySQL meta row under GET_LOCK. Memory `TokenBucket`.

Denied admission returns no reservation; Redis may leave the job ready; MySQL/Memory delay `available_at`.

## Alternatives

Sliding window is harder to store atomically. Multiple algorithms were rejected for API surface.

## Burst

A full bucket allows `capacity` jobs at once, then refills at `refill_per_second`.
