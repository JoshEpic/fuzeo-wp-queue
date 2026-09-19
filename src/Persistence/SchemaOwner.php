<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Persistence;

/**
 * Fuzeo Queue owns schema. Consuming plugins never version or migrate queue tables.
 */
final class SchemaOwner
{
    public const PACKAGE = 'fuzeowp/queue';
    public const OPTION_VERSION = 'fuzeo_queue_schema_version';
    public const OPTION_LOCK = 'fuzeo_queue_schema_lock';

    /**
     * Production schema version. 4 adds uniqueness, idempotency, and schedules.
     */
    public const CURRENT_VERSION = 4;
}
