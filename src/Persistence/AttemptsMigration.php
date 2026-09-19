<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Persistence;

final class AttemptsMigration implements Migration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function version(): int
    {
        return 3;
    }

    public function description(): string
    {
        return 'Create Fuzeo Queue attempt/failure history table.';
    }

    public function up(): void
    {
        $attempts = Schema::quoteTable($this->connection->prefix(), Schema::ATTEMPTS);

        $this->connection->execute(
            'CREATE TABLE IF NOT EXISTS ' . $attempts . ' (
                `attempt_id` CHAR(26) NOT NULL,
                `job_id` CHAR(26) NOT NULL,
                `attempt` INT UNSIGNED NOT NULL,
                `outcome` VARCHAR(32) NOT NULL,
                `job_type` VARCHAR(128) NOT NULL,
                `queue` VARCHAR(64) NOT NULL,
                `worker_id` VARCHAR(26) NULL,
                `reservation_token` CHAR(26) NULL,
                `origin_package` VARCHAR(191) NOT NULL,
                `network_id` BIGINT UNSIGNED NOT NULL,
                `site_id` BIGINT NOT NULL,
                `scope` VARCHAR(16) NOT NULL,
                `failure_class` VARCHAR(191) NULL,
                `sanitized_message` TEXT NULL,
                `sanitized_trace` TEXT NULL,
                `will_retry` TINYINT(1) NOT NULL DEFAULT 0,
                `next_available_at` DATETIME(6) NULL,
                `terminal_reason` VARCHAR(64) NULL,
                `failed_at` DATETIME(6) NOT NULL,
                PRIMARY KEY (`attempt_id`),
                KEY `lookup_job` (`job_id`, `attempt`),
                KEY `lookup_failed_at` (`failed_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
