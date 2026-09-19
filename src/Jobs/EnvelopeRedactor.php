<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Jobs;

use Fuzeo\Queue\Support\SecretRedactor;

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
            'attempt' => $envelope->attempt,
            'max_attempts' => $envelope->maxAttempts,
            'network_id' => $envelope->context->networkId,
            'site_id' => $envelope->context->siteId,
            'origin' => $envelope->origin->package,
            'payload_redacted' => !$includePayload,
        ];

        if ($includePayload) {
            $summary['payload'] = (new SecretRedactor())->redactMap($envelope->payload);
        }

        return $summary;
    }
}
