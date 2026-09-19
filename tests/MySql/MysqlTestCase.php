<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\MySql;

use Fuzeo\Queue\Persistence\PdoConnection;
use PHPUnit\Framework\TestCase;

abstract class MysqlTestCase extends TestCase
{
    protected PdoConnection $connection;

    protected string $dsn;

    protected string $user;

    protected string $password;

    protected function setUp(): void
    {
        parent::setUp();
        $dsn = getenv('FUZEO_QUEUE_TEST_DSN');
        if (!is_string($dsn) || $dsn === '') {
            $host = getenv('FUZEO_QUEUE_TEST_DB_HOST') ?: '';
            if ($host === '') {
                self::markTestSkipped('Set FUZEO_QUEUE_TEST_DSN or FUZEO_QUEUE_TEST_DB_HOST to run MySQL tests.');
            }
            $port = getenv('FUZEO_QUEUE_TEST_DB_PORT') ?: '3306';
            $name = getenv('FUZEO_QUEUE_TEST_DB_NAME') ?: 'fuzeo_queue_test';
            $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4';
        }
        $this->dsn = $dsn;
        $this->user = getenv('FUZEO_QUEUE_TEST_DB_USER') ?: 'root';
        $password = getenv('FUZEO_QUEUE_TEST_DB_PASS');
        $this->password = $password === false ? 'root' : $password;
        try {
            $this->connection = PdoConnection::fromDsn($this->dsn, $this->user, $this->password, 'wp_');
        } catch (\PDOException $exception) {
            self::markTestSkipped('MySQL is not reachable: ' . $exception->getMessage());
        }
        if (!$this->connection->supportsSkipLocked()) {
            self::markTestSkipped('Database does not support FOR UPDATE SKIP LOCKED.');
        }
        $this->dropTables();
    }

    protected function tearDown(): void
    {
        $this->dropTables();
        parent::tearDown();
    }

    /**
     * MySQL 8 exposes @@transaction_isolation; MariaDB 10.x still uses @@tx_isolation.
     */
    protected function sessionIsolation(): string
    {
        try {
            $row = $this->connection->selectOne('SELECT @@session.transaction_isolation AS iso');
        } catch (\Fuzeo\Queue\Exceptions\DriverException) {
            $row = $this->connection->selectOne('SELECT @@session.tx_isolation AS iso');
        }
        $iso = strtoupper(str_replace([' ', '_'], '-', (string) ($row['iso'] ?? '')));

        return $iso;
    }

    protected function dropTables(): void
    {
        foreach (['fuzeo_queue_jobs', 'fuzeo_queue_workers', 'fuzeo_queue_attempts', 'fuzeo_queue_meta', 'fuzeo_queue_unique', 'fuzeo_queue_idempotency', 'fuzeo_queue_schedules', 'fuzeo_queue_schedule_claims', 'fuzeo_queue_schedulers', 'fuzeo_queue_chains', 'fuzeo_queue_chain_steps', 'fuzeo_queue_batches', 'fuzeo_queue_batch_members', 'fuzeo_queue_metrics', 'fuzeo_queue_audit'] as $table) {
            try {
                $this->connection->execute('DROP TABLE IF EXISTS `' . $this->connection->prefix() . $table . '`');
            } catch (\Throwable) {
            }
        }
    }
}
