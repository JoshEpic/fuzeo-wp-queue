# ADR-085 Metrics performance and cardinality limits

## Context

A 10ms job must not become 50ms because of metrics.

## Decision

Exact counts via atomic increments. Approximate percentiles via 17 fixed buckets. Dimensions: none, queue, job_type, origin, optional site, outcome, driver. Never job id / user id / tags. Site dimension can be disabled. Redis keys are bucketed with TTL; no `KEYS`.

## Alternatives

Sampling by default. High-cardinality tag metrics.

## Performance

Writes isolated; overhead is extra INSERTs/HINCRBY after ACK.

## Security

Cardinality abuse is a storage issue, not a secrecy issue.

## Failure behavior

Isolating recorder.

## Compatibility

Internal `MetricName` vocabulary is not a public custom-metrics API.

## Future

If overhead is too high, buffer per-worker and flush on heartbeat.
