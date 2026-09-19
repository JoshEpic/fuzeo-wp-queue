# ADR-111 Migration persistence and reconciliation

## Decision

Schema 7 adds `fuzeo_queue_migrations` on the WordPress MySQL control plane (also when Redis is the job backend). Unique key `(source_system, source_identifier, site_id, network_id)` makes execute idempotent. Crash recovery: destination created + source still present → destination stays disabled; destination present + source gone → enable destination.
