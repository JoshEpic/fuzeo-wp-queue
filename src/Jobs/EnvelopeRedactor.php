<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Jobs;

final class EnvelopeRedactor
{
    /**
     * Safe summary for logs and diagnostics. Payloads are omitted by default.
     *
     * @return array<string, mixed>
     */
    public static function summarize(Envelope $envelope, bool $includePayload = false): array
    {
        $summary = [
            'job_id' => $envelope->jobId,
            'job_type' => $envelope->jobType,
            'schema_version' => $envelope->schemaVersion,
            'queue' => $envelope->queue,
            'state' => $envelope->state->value,
            'network_id' => $envelope->context->networkId,
            'site_id' => $envelope->context->siteId,
            'origin' => $envelope->origin->package,
            'payload_redacted' => !$includePayload,
        ];

        if ($includePayload) {
            $summary['payload'] = $envelope->payload;
        }

        return $summary;
    }
}
