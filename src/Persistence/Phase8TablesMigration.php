<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Persistence;

final class Phase8TablesMigration implements Migration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function version(): int
    {
        return 6;
    }

    public function description(): string
    {
        return 'Create Fuzeo Queue metrics and audit tables and worker observation columns.';
    }

    public function up(): void
    {
        $metrics = Schema::quoteTable($this->connection->prefix(), Schema::METRICS);
        $audit = Schema::quoteTable($this->connection->prefix(), Schema::AUDIT);
        $jobs = Schema::quoteTable($this->connection->prefix(), Schema::JOBS);
        $workers = Schema::quoteTable($this->connection->prefix(), Schema::WORKERS);

        $this->connection->execute(
            'CREATE TABLE IF NOT EXISTS ' . $metrics . ' (
                `bucket` DATETIME NOT NULL,
                `resolution` VARCHAR(8) NOT NULL,
                `metric` VARCHAR(96) NOT NULL,
                `dimension_type` VARCHAR(32) NOT NULL,
                `dimension_value` VARCHAR(191) NOT NULL,
                `count` BIGINT NOT NULL DEFAULT 0,
                `sum` DOUBLE NOT NULL DEFAULT 0,
                `min` DOUBLE NOT NULL DEFAULT 0,
                `max` DOUBLE NOT NULL DEFAULT 0,
                PRIMARY KEY (`bucket`, `resolution`, `metric`, `dimension_type`, `dimension_value`),
                KEY `lookup_metric` (`metric`, `resolution`, `bucket`),
                KEY `lookup_dimension` (`dimension_type`, `dimension_value`, `metric`, `bucket`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->connection->execute(
            'CREATE TABLE IF NOT EXISTS ' . $audit . ' (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `occurred_at` DATETIME(6) NOT NULL,
                `category` VARCHAR(16) NOT NULL,
                `action` VARCHAR(64) NOT NULL,
                `user_id` BIGINT NOT NULL DEFAULT 0,
                `resource_type` VARCHAR(32) NOT NULL,
                `resource_id` VARCHAR(64) NOT NULL,
                `network_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
                `site_id` BIGINT NOT NULL DEFAULT 0,
                `details` TEXT NULL,
                PRIMARY KEY (`id`),
                KEY `lookup_time` (`occurred_at`),
                KEY `lookup_action` (`category`, `action`, `occurred_at`),
                KEY `lookup_resource` (`resource_type`, `resource_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        if (!Schema::hasColumn($this->connection, Schema::WORKERS, 'current_job_id')) {
            $this->connection->execute(
                'ALTER TABLE ' . $workers . '
                 ADD COLUMN `current_job_id` CHAR(26) NULL,
                 ADD COLUMN `runtime_generation` VARCHAR(64) NULL,
                 ADD COLUMN `recycle_reason` VARCHAR(64) NULL,
                 ADD COLUMN `recent_jobs` TEXT NULL'
            );
        }

        foreach (['lookup_created', 'lookup_origin_state', 'lookup_site_state', 'lookup_type_state'] as $index) {
            if (Schema::hasIndex($this->connection, Schema::JOBS, $index)) {
                continue;
            }
            $definition = match ($index) {
                'lookup_created' => '(`state`, `created_at`)',
                'lookup_origin_state' => '(`origin_package`, `state`, `created_at`)',
                'lookup_site_state' => '(`site_id`, `state`, `created_at`)',
                default => '(`job_type`, `state`, `created_at`)',
            };
            $this->connection->execute(
                'ALTER TABLE ' . $jobs . ' ADD INDEX `' . $index . '` ' . $definition
            );
        }
    }
}
