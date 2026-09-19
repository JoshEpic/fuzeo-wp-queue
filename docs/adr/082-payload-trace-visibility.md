# ADR-082 Payload and trace visibility

## Context

Job payloads and traces can contain secrets and paths.

## Decision

Default summaries omit payload. Display requires `fuzeo_queue_view_payload` or manage. Always `SecretRedactor`. Traces require manage-level `canViewTrace`. CLI `--payload` is an operator opt-in.

## Alternatives

Never show payload. Separate encryption.

## Performance

Redaction is recursive key matching, not full-text search.

## Security

No payload search. Metrics never store payloads.

## Failure behavior

Insufficient capability returns job metadata without payload/trace.

## Compatibility

Existing `EnvelopeRedactor` contract.

## Future

Field-level ACLs are not planned.
