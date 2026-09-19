<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Persistence;

final class Phase6TablesMigration implements Migration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function version(): int
    {
        return 5;
    }

    public function description(): string
    {
        return 'Create Fuzeo Queue chain and batch tables and job cancellation column.';
    }

    public function up(): void
    {
        $jobs = Schema::quoteTable($this->connection->prefix(), Schema::JOBS);
        $chains = Schema::quoteTable($this->connection->prefix(), Schema::CHAINS);
        $steps = Schema::quoteTable($this->connection->prefix(), Schema::CHAIN_STEPS);
        $batches = Schema::quoteTable($this->connection->prefix(), Schema::BATCHES);
        $members = Schema::quoteTable($this->connection->prefix(), Schema::BATCH_MEMBERS);

        $this->connection->execute(
            'ALTER TABLE ' . $jobs . ' ADD COLUMN `cancel_requested` TINYINT(1) NOT NULL DEFAULT 0'
        );

        $this->connection->execute(
            'CREATE TABLE IF NOT EXISTS ' . $chains . ' (
                `chain_id` CHAR(26) NOT NULL,
                `origin_package` VARCHAR(191) NOT NULL,
                `origin_version` VARCHAR(64) NOT NULL,
                `network_id` BIGINT UNSIGNED NOT NULL,
                `site_id` BIGINT NOT NULL,
                `scope` VARCHAR(16) NOT NULL,
                `state` VARCHAR(16) NOT NULL,
                `current_step` INT UNSIGNED NOT NULL DEFAULT 1,
                `total_steps` INT UNSIGNED NOT NULL,
                `failure_policy` VARCHAR(16) NOT NULL,
                `failed_step` INT UNSIGNED NULL,
                `failed_job_id` CHAR(26) NULL,
                `failure_reason` VARCHAR(191) NULL,
                `cancel_requested` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME(6) NOT NULL,
                `started_at` DATETIME(6) NULL,
                `completed_at` DATETIME(6) NULL,
                `cancelled_at` DATETIME(6) NULL,
                `failed_at` DATETIME(6) NULL,
                `metadata` TEXT NULL,
                PRIMARY KEY (`chain_id`),
                KEY `lookup_state` (`state`, `created_at`),
                KEY `lookup_origin` (`origin_package`, `state`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->connection->execute(
            'CREATE TABLE IF NOT EXISTS ' . $steps . ' (
                `chain_id` CHAR(26) NOT NULL,
                `step_number` INT UNSIGNED NOT NULL,
                `job_type` VARCHAR(128) NOT NULL,
                `job_schema_version` INT UNSIGNED NOT NULL,
                `payload` MEDIUMTEXT NOT NULL,
                `queue` VARCHAR(64) NOT NULL,
                `priority` INT NOT NULL DEFAULT 0,
                `max_attempts` INT UNSIGNED NOT NULL,
                `timeout_seconds` INT UNSIGNED NOT NULL,
                `unique_key` VARCHAR(191) NULL,
                `job_id` CHAR(26) NULL,
                `status` VARCHAR(16) NOT NULL,
                `parent_job_id` CHAR(26) NULL,
                `metadata` TEXT NULL,
                `tags` TEXT NULL,
                PRIMARY KEY (`chain_id`, `step_number`),
                KEY `lookup_job` (`job_id`),
                KEY `lookup_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->connection->execute(
            'CREATE TABLE IF NOT EXISTS ' . $batches . ' (
                `batch_id` CHAR(26) NOT NULL,
                `name` VARCHAR(64) NULL,
                `origin_package` VARCHAR(191) NOT NULL,
                `origin_version` VARCHAR(64) NOT NULL,
                `network_id` BIGINT UNSIGNED NOT NULL,
                `site_id` BIGINT NOT NULL,
                `scope` VARCHAR(16) NOT NULL,
                `state` VARCHAR(16) NOT NULL,
                `total_jobs` INT UNSIGNED NOT NULL,
                `completed_jobs` INT UNSIGNED NOT NULL DEFAULT 0,
                `failed_jobs` INT UNSIGNED NOT NULL DEFAULT 0,
                `cancelled_jobs` INT UNSIGNED NOT NULL DEFAULT 0,
                `failure_policy` VARCHAR(16) NOT NULL,
                `cancel_requested` TINYINT(1) NOT NULL DEFAULT 0,
                `then_job_type` VARCHAR(128) NULL,
                `then_payload` MEDIUMTEXT NULL,
                `then_queue` VARCHAR(64) NULL,
                `catch_job_type` VARCHAR(128) NULL,
                `catch_payload` MEDIUMTEXT NULL,
                `catch_queue` VARCHAR(64) NULL,
                `then_job_id` CHAR(26) NULL,
                `catch_job_id` CHAR(26) NULL,
                `created_at` DATETIME(6) NOT NULL,
                `started_at` DATETIME(6) NULL,
                `completed_at` DATETIME(6) NULL,
                `cancelled_at` DATETIME(6) NULL,
                `failed_at` DATETIME(6) NULL,
                `metadata` TEXT NULL,
                PRIMARY KEY (`batch_id`),
                KEY `lookup_state` (`state`, `created_at`),
                KEY `lookup_origin` (`origin_package`, `state`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->connection->execute(
            'CREATE TABLE IF NOT EXISTS ' . $members . ' (
                `batch_id` CHAR(26) NOT NULL,
                `member_index` INT UNSIGNED NOT NULL,
                `job_type` VARCHAR(128) NOT NULL,
                `job_schema_version` INT UNSIGNED NOT NULL,
                `payload` MEDIUMTEXT NOT NULL,
                `queue` VARCHAR(64) NOT NULL,
                `priority` INT NOT NULL DEFAULT 0,
                `max_attempts` INT UNSIGNED NOT NULL,
                `timeout_seconds` INT UNSIGNED NOT NULL,
                `unique_key` VARCHAR(191) NULL,
                `job_id` CHAR(26) NULL,
                `status` VARCHAR(16) NOT NULL,
                `metadata` TEXT NULL,
                `tags` TEXT NULL,
                PRIMARY KEY (`batch_id`, `member_index`),
                KEY `lookup_job` (`job_id`),
                KEY `lookup_status` (`batch_id`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
