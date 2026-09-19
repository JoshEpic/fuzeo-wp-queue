# ADR-032 Distributed concurrency limits

## Context

Worker process count is not the same as how many jobs of a type may run.

## Decision

Queue-level limits: config `concurrency` map `queue => max`. Redis: zset of job ids scored by lease. MySQL: `GET_LOCK` slots `fq:{hash}:{slot}` held on the worker connection. Memory: in-process count. Capability `queue_concurrency`. `ConcurrencyLimit::max(4)->forQueue('imports')` is the declarative helper; runtime uses config.

Keys are shaped as queue-scoped now; site/origin scopes can be added later without changing the job envelope.

## Alternatives

Process-local counters cannot span hosts. Redis-only API would surprise MySQL users.

## Failure behavior

If limits are configured on a driver without the capability, workers throw `ConfigurationException` at start.
