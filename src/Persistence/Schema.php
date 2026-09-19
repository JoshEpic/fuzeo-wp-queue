<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Persistence;

use Fuzeo\Queue\Exceptions\SchemaException;

final class Schema
{
    public const JOBS = 'fuzeo_queue_jobs';
    public const WORKERS = 'fuzeo_queue_workers';
    public const ATTEMPTS = 'fuzeo_queue_attempts';
    public const META = 'fuzeo_queue_meta';
    public const LOCK = 'fuzeo_queue_schema';
    public const META_VERSION = 'schema_version';

    public static function quoteTable(string $prefix, string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $prefix)) {
            throw new SchemaException('Invalid table prefix.');
        }
        if (!preg_match('/^[a-z0-9_]+$/', $name)) {
            throw new SchemaException('Invalid table name.');
        }

        return '`' . $prefix . $name . '`';
    }
}
