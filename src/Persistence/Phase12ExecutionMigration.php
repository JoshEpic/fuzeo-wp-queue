<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Persistence;

final class Phase12ExecutionMigration implements Migration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function version(): int
    {
        return 8;
    }

    public function description(): string
    {
        return 'Add indexed execution_class and timeout_seconds for capability-aware reservation, plus worker process_type.';
    }

    public function up(): void
    {
        $jobs = Schema::quoteTable($this->connection->prefix(), Schema::JOBS);
        $workers = Schema::quoteTable($this->connection->prefix(), Schema::WORKERS);
        if (!Schema::hasColumn($this->connection, Schema::JOBS, 'execution_class')) {
            $this->connection->execute(
                'ALTER TABLE ' . $jobs . " ADD COLUMN `execution_class` VARCHAR(16) NOT NULL DEFAULT 'standard'"
            );
        }
        if (!Schema::hasColumn($this->connection, Schema::JOBS, 'timeout_seconds')) {
            $this->connection->execute(
                'ALTER TABLE ' . $jobs . ' ADD COLUMN `timeout_seconds` INT UNSIGNED NOT NULL DEFAULT 60'
            );
        }
        if (!Schema::hasIndex($this->connection, Schema::JOBS, 'reserve_compat')) {
            $this->connection->execute(
                'ALTER TABLE ' . $jobs . ' ADD KEY `reserve_compat` (`queue`, `state`, `execution_class`, `timeout_seconds`, `priority`, `available_at`, `job_id`)'
            );
        }
        if (!Schema::hasColumn($this->connection, Schema::WORKERS, 'process_type')) {
            $this->connection->execute(
                'ALTER TABLE ' . $workers . " ADD COLUMN `process_type` VARCHAR(32) NOT NULL DEFAULT 'persistent'"
            );
        }
    }
}
