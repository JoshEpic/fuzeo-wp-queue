# ADR-116 Interop schema / control-plane storage

## Decision

Migration history is WordPress MySQL even when jobs live in Redis: admin/audit metadata must survive Redis replacement and align with WP-Cron/AS. Redis Lua remains version 4. MySQL job schema becomes 7 (`fuzeo_queue_migrations` only). Compatibility series remains 1.
