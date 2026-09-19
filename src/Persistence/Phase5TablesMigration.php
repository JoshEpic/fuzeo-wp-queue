<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Persistence;

final class Phase5TablesMigration implements Migration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function version(): int
    {
        return 4;
    }

    public function description(): string
    {
        return 'Create Fuzeo Queue uniqueness, idempotency, and schedule tables.';
    }

    public function up(): void
    {
        $unique = Schema::quoteTable($this->connection->prefix(), Schema::UNIQUE);
        $idemp = Schema::quoteTable($this->connection->prefix(), Schema::IDEMPOTENCY);
        $schedules = Schema::quoteTable($this->connection->prefix(), Schema::SCHEDULES);
        $claims = Schema::quoteTable($this->connection->prefix(), Schema::SCHEDULE_CLAIMS);
        $schedulers = Schema::quoteTable($this->connection->prefix(), Schema::SCHEDULERS);

        $this->connection->execute(
            'CREATE TABLE IF NOT EXISTS ' . $unique . ' (
                `unique_id` CHAR(64) NOT NULL,
                `job_id` VARCHAR(64) NOT NULL,
                `kind` VARCHAR(16) NOT NULL,
                `unique_key` VARCHAR(191) NOT NULL,
                `origin_package` VARCHAR(191) NOT NULL,
                `job_type` VARCHAR(128) NOT NULL,
                `network_id` BIGINT UNSIGNED NOT NULL,
                `site_id` BIGINT NOT NULL,
                `scope` VARCHAR(16) NOT NULL,
                `expires_at` DATETIME(6) NULL,
                `created_at` DATETIME(6) NOT NULL,
                PRIMARY KEY (`unique_id`),
                KEY `lookup_job` (`job_id`),
                KEY `lookup_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->connection->execute(
            'CREATE TABLE IF NOT EXISTS ' . $idemp . ' (
                `idempotency_id` CHAR(64) NOT NULL,
                `idempotency_key` VARCHAR(191) NOT NULL,
                `status` VARCHAR(16) NOT NULL,
                `owner_token` CHAR(26) NOT NULL,
                `result_json` TEXT NULL,
                `network_id` BIGINT UNSIGNED NOT NULL,
                `site_id` BIGINT NOT NULL,
                `scope` VARCHAR(16) NOT NULL,
                `started_at` DATETIME(6) NOT NULL,
                `completed_at` DATETIME(6) NULL,
                `expires_at` DATETIME(6) NOT NULL,
                PRIMARY KEY (`idempotency_id`),
                UNIQUE KEY `lookup_owner` (`owner_token`),
                KEY `lookup_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->connection->execute(
            'CREATE TABLE IF NOT EXISTS ' . $schedules . ' (
                `schedule_id` CHAR(64) NOT NULL,
                `name` VARCHAR(64) NOT NULL,
                `origin_package` VARCHAR(191) NOT NULL,
                `origin_version` VARCHAR(64) NOT NULL,
                `job_type` VARCHAR(128) NOT NULL,
                `job_schema_version` INT UNSIGNED NOT NULL,
                `payload` MEDIUMTEXT NOT NULL,
                `queue` VARCHAR(64) NOT NULL,
                `priority` INT NOT NULL DEFAULT 0,
                `network_id` BIGINT UNSIGNED NOT NULL,
                `site_id` BIGINT NOT NULL,
                `scope` VARCHAR(16) NOT NULL,
                `expression_type` VARCHAR(16) NOT NULL,
                `expression_value` VARCHAR(191) NOT NULL,
                `timezone` VARCHAR(64) NOT NULL,
                `next_run_at` DATETIME(6) NOT NULL,
                `last_run_at` DATETIME(6) NULL,
                `last_occurrence_id` VARCHAR(64) NULL,
                `last_result` VARCHAR(64) NULL,
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `overlap_policy` VARCHAR(16) NOT NULL,
                `catch_up_policy` VARCHAR(16) NOT NULL,
                `blocked_reason` VARCHAR(64) NULL,
                `metadata` TEXT NULL,
                `created_at` DATETIME(6) NOT NULL,
                `updated_at` DATETIME(6) NOT NULL,
                PRIMARY KEY (`schedule_id`),
                KEY `due_enabled` (`enabled`, `next_run_at`),
                UNIQUE KEY `origin_name_scope` (`origin_package`, `name`, `network_id`, `site_id`, `scope`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->connection->execute(
            'CREATE TABLE IF NOT EXISTS ' . $claims . ' (
                `occurrence_id` CHAR(64) NOT NULL,
                `schedule_id` CHAR(64) NOT NULL,
                `intended_run_at` DATETIME(6) NOT NULL,
                `owner_token` CHAR(26) NOT NULL,
                `status` VARCHAR(16) NOT NULL,
                `job_id` CHAR(26) NULL,
                `lease_expires_at` DATETIME(6) NOT NULL,
                `created_at` DATETIME(6) NOT NULL,
                PRIMARY KEY (`occurrence_id`),
                KEY `lookup_lease` (`status`, `lease_expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->connection->execute(
            'CREATE TABLE IF NOT EXISTS ' . $schedulers . ' (
                `scheduler_id` CHAR(26) NOT NULL,
                `hostname` VARCHAR(191) NOT NULL,
                `pid` INT UNSIGNED NOT NULL,
                `started_at` DATETIME(6) NOT NULL,
                `last_heartbeat_at` DATETIME(6) NOT NULL,
                `status` VARCHAR(32) NOT NULL,
                `runtime_version` VARCHAR(32) NOT NULL,
                PRIMARY KEY (`scheduler_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
