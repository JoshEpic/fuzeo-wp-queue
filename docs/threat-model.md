# Threat model (1.0)

Fuzeo Queue is at-least-once infrastructure. Duplicate execution after a crash is expected.

| Threat | Mitigation |
| --- | --- |
| Arbitrary object injection (`unserialize`) | Job data is JSON only. `serialize`/`unserialize` are not used on queue state. |
| Malicious PHP objects in payloads | `JsonPayloadSerializer` rejects objects, resources, closures. |
| Enormous / deep JSON | `max_payload_bytes`, `max_payload_depth`, `max_payload_string_bytes`. |
| Metadata/tag cardinality DoS | `max_tags`, tag length, metadata bytes/key length. Fail at dispatch. |
| SQL injection | Bound placeholders; table names quoted through `Schema::quoteTable`. |
| Redis command/script injection | Versioned Lua, typed ARGV, validated queue names and keys. |
| Job/origin spoofing | Handlers resolved only from the registry; types validated; origin recorded from registration. |
| Reservation-token attacks | ACK/release/fail/extend require the owning token. |
| Lock / idempotency ownership | Tokens + leases; stale owners cannot complete newer claims. |
| REST authorization | Capability checks; network manage for fleet; ID validation; filter whitelist. |
| CSRF | `check_admin_referer` on admin-post actions; REST nonce/auth. |
| Capability escalation | Site admins cannot recycle the network fleet or inspect other sites. |
| Multisite data exposure | Operator site filter; 403 on cross-site IDs. |
| Payload/trace leakage | Redaction by default; traces omit arguments; size bounded; diagnostics omit payloads. |
| CLI destructive ops | `--force` for unique release; migrate/drain/restart emit structured results. |
| Metrics cardinality | Low-cardinality dimensions; site dimension configurable (`metrics_include_site`). |
| SSRF | Queue does not fetch attacker URLs. Handlers might; that is consumer code. |
| Large Redis catalogs | Bounded scans; approximate totals labeled. |

Lua scripts remain small and versioned (v4 in 1.0). Clock skew: leases and schedules prefer backend time where the atomic operation runs.
