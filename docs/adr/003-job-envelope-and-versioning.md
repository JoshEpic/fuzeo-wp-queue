# ADR-003 Job envelope and versioning

## Context

Workers, admin UIs, and future languages must read jobs without the original PHP class. Job payload schemas evolve independently of the queue envelope.

## Decision

Canonical envelope version `1` with ULID `job_id`. Envelope fields are listed on `Envelope`. `envelope_version` is distinct from `schema_version`.

ULID is used because it is 128-bit, JSON-safe, and time-sortable for later clustered indexes, without depending on UUID libraries.

Unsupported `envelope_version` values throw `UnsupportedEnvelopeException`. The runtime does not guess.

Optional fields may be added to v1 without a bump. Removing, renaming, or changing field meaning requires a new envelope version.

## Alternatives considered

- **UUID v4:** Unsorted, worse locality. Rejected for queue rows.
- **Auto-increment IDs only:** Leaks volume and complicates multi-writer imports. Can exist as an internal PK in Phase 2 alongside ULID.

## Consequences

Envelope tests lock the persisted key names. Class names are not in the envelope.

## Future implications

Upcasters, if needed, will live beside envelope versions. Phase 1 does not ship a migration engine for envelopes.
