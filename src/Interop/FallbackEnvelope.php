<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

use Fuzeo\Queue\Exceptions\InteropException;
use Fuzeo\Queue\Jobs\ExecutionContext;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Serialization\PayloadSerializer;

/**
 * Portable subset stored on Action Scheduler fallback actions.
 * Not Fuzeo reservation state.
 */
final class FallbackEnvelope
{
    public const HOOK = 'fuzeo_queue_interop_run';

    public const SCHEMA = 1;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $jobType,
        public readonly int $schemaVersion,
        public readonly array $payload,
        public readonly Origin $origin,
        public readonly ExecutionContext $context,
        public readonly string $queue,
    ) {
    }

    public static function groupFor(Origin $origin): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $origin->package) ?? 'fuzeo');

        return 'fuzeo-interop-' . trim($slug, '-');
    }

    /**
     * @return array<string, mixed>
     */
    public function toActionArgs(PayloadSerializer $serializer): array
    {
        return [
            'fuzeo_runtime' => 1,
            'schema_version' => self::SCHEMA,
            'job_type' => $this->jobType,
            'job_schema_version' => $this->schemaVersion,
            'payload' => $serializer->normalize($this->payload),
            'origin' => $this->origin->toArray(),
            'context' => $this->context->toArray(),
            'queue' => $this->queue,
        ];
    }

    /**
     * @param array<string, mixed> $args
     */
    public static function fromActionArgs(array $args): self
    {
        if (($args['fuzeo_runtime'] ?? null) !== 1) {
            throw new InteropException('Action is not a Fuzeo runtime-adapter envelope.');
        }
        $payload = $args['payload'] ?? [];
        $origin = $args['origin'] ?? null;
        $context = $args['context'] ?? null;
        if (!is_array($payload) || !is_array($origin) || !is_array($context)) {
            throw new InteropException('Fallback envelope is missing payload, origin, or context.');
        }

        return new self(
            jobType: (string) ($args['job_type'] ?? ''),
            schemaVersion: (int) ($args['job_schema_version'] ?? 0),
            payload: $payload,
            origin: Origin::fromArray($origin),
            context: ExecutionContext::fromArray($context),
            queue: (string) ($args['queue'] ?? 'default'),
        );
    }
}
