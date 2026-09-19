# Threat model (Phase 1)

| Threat | Mitigation |
| --- | --- |
| Arbitrary object injection via PHP `unserialize` | Job data is JSON only. `serialize`/`unserialize` are not used. |
| Malicious serialized PHP payloads | JSON decoder rejects non-objects; objects/resources/closures in arrays fail `JsonPayloadSerializer`. |
| Malformed JSON | `JSON_THROW_ON_ERROR` → `SerializationException`. |
| Enormous payloads | `max_payload_bytes` (default 256 KiB). |
| Deeply nested payloads | `max_payload_depth` (default 32). |
| Spoofed job types | Workers resolve handlers only from the registry. Unknown types fail. Types are validated. |
| Duplicate runtime | Global candidate list + single Coordinator boot. |
| Incompatible bundled versions | `IncompatibleRuntimeException`; no mixed runtime. |
| Invalid site IDs | `ExecutionContext` validation. |
| Untrusted metadata | Same JSON serializer rules as payload. |
| Malformed queue names | `QueueName` allow-list pattern. |
| Payload leakage in logs | `EnvelopeRedactor` omits payload by default. |
| REST/admin payload leak | Server-side `QueueAccess`; `SecretRedactor`; payload opt-in. |
| Cross-site job inspect | `Operator` site filter; 403 on other-site IDs. |
| Arbitrary REST sort/SQL | Whitelisted filters only. |

Make jobs idempotent. At-least-once delivery means handlers may observe duplicates.
