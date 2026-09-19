<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Persistence;

final class DatabaseMigrationRepository implements MigrationRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function currentVersion(): int
    {
        $table = Schema::quoteTable($this->connection->prefix(), Schema::META);
        try {
            $row = $this->connection->selectOne(
                'SELECT `meta_value` FROM ' . $table . ' WHERE `meta_key` = ?',
                [Schema::META_VERSION]
            );
        } catch (\Throwable) {
            return 0;
        }

        if ($row === null) {
            return 0;
        }
        $value = $row['meta_value'] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    public function record(int $version): void
    {
        $table = Schema::quoteTable($this->connection->prefix(), Schema::META);
        $this->connection->execute(
            'INSERT INTO ' . $table . ' (`meta_key`, `meta_value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `meta_value` = VALUES(`meta_value`)',
            [Schema::META_VERSION, (string) $version]
        );
    }
}
