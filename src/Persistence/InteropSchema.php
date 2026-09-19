<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Persistence;

/**
 * WordPress-database control plane for interoperability history.
 * Used even when Redis is the queue backend.
 */
final class InteropSchema
{
    public static function ensure(Connection $connection): void
    {
        $table = Schema::quoteTable($connection->prefix(), Schema::MIGRATIONS);
        $connection->execute(
            'CREATE TABLE IF NOT EXISTS ' . $table . ' (
                `migration_id` CHAR(26) NOT NULL,
                `descriptor_id` VARCHAR(191) NOT NULL,
                `descriptor_version` INT UNSIGNED NOT NULL,
                `source_system` VARCHAR(32) NOT NULL,
                `source_identifier` VARCHAR(191) NOT NULL,
                `source_snapshot` TEXT NULL,
                `destination_type` VARCHAR(32) NOT NULL,
                `destination_id` VARCHAR(64) NOT NULL,
                `origin_package` VARCHAR(191) NOT NULL,
                `site_id` BIGINT NOT NULL DEFAULT 0,
                `network_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
                `status` VARCHAR(32) NOT NULL,
                `migrated_at` DATETIME(6) NOT NULL,
                `rolled_back_at` DATETIME(6) NULL,
                `metadata` TEXT NULL,
                `rollback_available` TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (`migration_id`),
                UNIQUE KEY `uniq_source` (`source_system`, `source_identifier`, `site_id`, `network_id`),
                KEY `lookup_site` (`site_id`, `migrated_at`),
                KEY `lookup_origin` (`origin_package`, `migrated_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
