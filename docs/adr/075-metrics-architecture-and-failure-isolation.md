# ADR-075 Metrics architecture and failure isolation

## Context

Operators need throughput and latency without making Queue correctness depend on analytics.

## Decision

A backend-neutral `MetricRecorder` sits beside settlement. `IsolatingRecorder` swallows all write errors and marks metrics degraded. ACK/fail/cancel always complete first. Storage is `MetricsRepository` (Memory/MySQL/Redis).

## Alternatives

Write metrics in the same transaction as ACK. Dual-write to an APM vendor.

## Performance

Fan-out is low-cardinality dimensions only. Histogram buckets are fixed and mergeable.

## Security

No payloads, secrets, or job IDs in aggregates.

## Failure behavior

Metrics outage: jobs succeed; dashboard shows degraded.

## Compatibility

Schema v6 for MySQL metrics. Redis uses TTL hashes. Lua scripts remain v4.

## Future

Exporters (Prometheus/OTel) can wrap `MetricsQuery` later.
