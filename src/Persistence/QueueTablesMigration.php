<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Persistence;

/**
 * Create shared (network-level) queue tables. Owned by fuzeowp/queue.
 */
final class QueueTablesMigration implements Migration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function version(): int
    {
        return 2;
    }

    public function description(): string
    {
        return 'Create Fuzeo Queue jobs, workers, and schema metadata tables.';
    }

    public function up(): void
    {
        $jobs = Schema::quoteTable($this->connection->prefix(), Schema::JOBS);
        $workers = Schema::quoteTable($this->connection->prefix(), Schema::WORKERS);
        $meta = Schema::quoteTable($this->connection->prefix(), Schema::META);

        $this->connection->execute(
            'CREATE TABLE IF NOT EXISTS ' . $meta . ' (
                `meta_key` VARCHAR(64) NOT NULL,
                `meta_value` VARCHAR(191) NOT NULL,
                PRIMARY KEY (`meta_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->connection->execute(
            'CREATE TABLE IF NOT EXISTS ' . $jobs . ' (
                `job_id` CHAR(26) NOT NULL,
                `envelope_version` SMALLINT UNSIGNED NOT NULL,
                `job_type` VARCHAR(128) NOT NULL,
                `job_schema_version` INT UNSIGNED NOT NULL,
                `queue` VARCHAR(64) NOT NULL,
                `priority` INT NOT NULL DEFAULT 0,
                `state` VARCHAR(16) NOT NULL,
                `envelope` MEDIUMTEXT NOT NULL,
                `available_at` DATETIME(6) NOT NULL,
                `reserved_at` DATETIME(6) NULL,
                `lease_expires_at` DATETIME(6) NULL,
                `reservation_token` CHAR(26) NULL,
                `worker_id` VARCHAR(26) NULL,
                `attempt` INT UNSIGNED NOT NULL DEFAULT 0,
                `network_id` BIGINT UNSIGNED NOT NULL,
                `site_id` BIGINT NOT NULL,
                `scope` VARCHAR(16) NOT NULL,
                `origin_package` VARCHAR(191) NOT NULL,
                `origin_version` VARCHAR(64) NOT NULL,
                `created_at` DATETIME(6) NOT NULL,
                `updated_at` DATETIME(6) NOT NULL,
                `completed_at` DATETIME(6) NULL,
                `failed_at` DATETIME(6) NULL,
                `failure_class` VARCHAR(191) NULL,
                `failure_message` TEXT NULL,
                PRIMARY KEY (`job_id`),
                KEY `reserve_pending` (`queue`, `state`, `priority`, `available_at`, `job_id`),
                KEY `reserve_expired` (`queue`, `state`, `lease_expires_at`),
                KEY `lookup_state` (`state`, `queue`),
                KEY `lookup_context` (`network_id`, `site_id`, `job_type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->connection->execute(
            'CREATE TABLE IF NOT EXISTS ' . $workers . ' (
                `worker_id` CHAR(26) NOT NULL,
                `hostname` VARCHAR(191) NOT NULL,
                `pid` INT UNSIGNED NOT NULL,
                `started_at` DATETIME(6) NOT NULL,
                `last_heartbeat_at` DATETIME(6) NOT NULL,
                `queues` VARCHAR(255) NOT NULL,
                `status` VARCHAR(32) NOT NULL,
                `memory_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `processed_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `runtime_version` VARCHAR(32) NOT NULL,
                PRIMARY KEY (`worker_id`),
                KEY `lookup_status` (`status`, `last_heartbeat_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
