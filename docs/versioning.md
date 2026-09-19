# Semantic versioning

Before 1.0, breaking changes are allowed but must be called out in release notes.

After 1.0, Fuzeo Queue follows SemVer. Compatibility is not uniform across surfaces:

| Surface | Stability |
| --- | --- |
| Public PHP API (`Queue`, `Job`, value objects) | SemVer |
| Job envelope format | Stronger than methods. Additive optional fields may appear without a major bump. Removals/renames bump `envelope_version` and a package major. |
| Database schema | Stronger still. Migrations are owned by `fuzeowp/queue`. Never rewrite in place without a versioned migration. |
| Driver interfaces | Treat as public. New methods require a default or a major bump. |
| CLI (`wp fuzeo-queue`) | Public. Renames are breaking. |
| WordPress hooks (`fuzeo_queue_ready`, `fuzeo_queue_config`) | Public. |
| Configuration keys | Public. |

Persisted data outlives PHP methods. Envelope and schema compatibility is the release blocker, not class cosmetics.

`PackageInfo::COMPATIBILITY_SERIES` is the runtime coexistence key while multiple plugins bundle copies.
