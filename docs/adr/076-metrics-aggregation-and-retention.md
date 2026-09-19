# ADR-076 Metrics aggregation and retention

## Context

Per-job metric rows would grow without bound.

## Decision

Write the same increment into minute, hour, and day buckets. Defaults: 48h / 30d / 90d, configurable. Redis EXPIRE; MySQL chunked DELETE. Histograms use fixed millisecond bounds (`DurationHistogram`); percentiles are approximate.

## Alternatives

Downsample-only-on-prune. Exact t-digest.

## Performance

Three resolutions per write. Cardinality limited to queue, job type, origin, optional site, outcome, driver.

## Security

Dimension values truncated to 191 characters. Tags are not dimensions.

## Failure behavior

Prune is best-effort and never blocks workers.

## Compatibility

Historical throughput cannot be rebuilt from job rows after prune.

## Future

Optional disable of site dimension on huge networks (`metrics_include_site`).
