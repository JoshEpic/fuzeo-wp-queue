<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Persistence;

use Fuzeo\Queue\Exceptions\SchemaException;

final class Schema
{
    public const JOBS = 'fuzeo_queue_jobs';
    public const WORKERS = 'fuzeo_queue_workers';
    public const ATTEMPTS = 'fuzeo_queue_attempts';
    public const UNIQUE = 'fuzeo_queue_unique';
    public const IDEMPOTENCY = 'fuzeo_queue_idempotency';
    public const SCHEDULES = 'fuzeo_queue_schedules';
    public const SCHEDULE_CLAIMS = 'fuzeo_queue_schedule_claims';
    public const SCHEDULERS = 'fuzeo_queue_schedulers';
    public const CHAINS = 'fuzeo_queue_chains';
    public const CHAIN_STEPS = 'fuzeo_queue_chain_steps';
    public const BATCHES = 'fuzeo_queue_batches';
    public const BATCH_MEMBERS = 'fuzeo_queue_batch_members';
    public const METRICS = 'fuzeo_queue_metrics';
    public const AUDIT = 'fuzeo_queue_audit';
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

    public static function hasColumn(Connection $connection, string $table, string $column): bool
    {
        self::assertTable($table);
        self::assertIdentifier($column, 'column');
        $row = $connection->selectOne(
            'SELECT 1 AS ok FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$connection->prefix() . $table, $column]
        );

        return $row !== null;
    }

    public static function hasIndex(Connection $connection, string $table, string $index): bool
    {
        self::assertTable($table);
        self::assertIdentifier($index, 'index');
        $row = $connection->selectOne(
            'SELECT 1 AS ok FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
             LIMIT 1',
            [$connection->prefix() . $table, $index]
        );

        return $row !== null;
    }

    private static function assertTable(string $name): void
    {
        if (!preg_match('/^[a-z0-9_]+$/', $name)) {
            throw new SchemaException('Invalid table name.');
        }
    }

    private static function assertIdentifier(string $name, string $kind): void
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new SchemaException('Invalid ' . $kind . ' name.');
        }
    }
}
