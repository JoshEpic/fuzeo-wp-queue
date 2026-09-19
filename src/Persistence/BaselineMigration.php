<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Persistence;

/**
 * Schema v1 records ownership. When a database connection is present it creates
 * the metadata table so later versions can be stored durably.
 */
final class BaselineMigration implements Migration
{
    public function __construct(private readonly ?Connection $connection = null)
    {
    }

    public function version(): int
    {
        return 1;
    }

    public function description(): string
    {
        return 'Record Fuzeo Queue schema ownership.';
    }

    public function up(): void
    {
        if ($this->connection === null) {
            return;
        }

        $meta = Schema::quoteTable($this->connection->prefix(), Schema::META);
        $this->connection->execute(
            'CREATE TABLE IF NOT EXISTS ' . $meta . ' (
                `meta_key` VARCHAR(64) NOT NULL,
                `meta_value` VARCHAR(191) NOT NULL,
                PRIMARY KEY (`meta_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
