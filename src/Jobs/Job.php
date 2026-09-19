<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Jobs;

/**
 * Dispatchable job. Persist identities and JSON-safe values, never live objects.
 *
 * Class names are a runtime implementation detail. The stable persistence
 * contract is type() + schemaVersion() + payload().
 */
interface Job
{
    /**
     * Stable identifier such as "acme.process_order".
     */
    public static function type(): string;

    /**
     * Payload schema version for this job type. Bump when payload meaning changes.
     */
    public static function schemaVersion(): int;

    /**
     * JSON-compatible map of primitive values and nested arrays.
     *
     * @return array<string, mixed>
     */
    public function payload(): array;
}
