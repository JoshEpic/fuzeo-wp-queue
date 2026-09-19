<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\MySql;

use Fuzeo\Queue\Persistence\BaselineMigration;
use Fuzeo\Queue\Persistence\DatabaseMigrationRepository;
use Fuzeo\Queue\Persistence\MigrationRunner;
use Fuzeo\Queue\Persistence\MysqlAdvisoryLock;
use Fuzeo\Queue\Persistence\QueueTablesMigration;
use Fuzeo\Queue\Persistence\Schema;
use Fuzeo\Queue\Persistence\SchemaOwner;
use Fuzeo\Queue\Runtime\Coordinator;

final class SchemaUpgradeTest extends MysqlTestCase
{
    protected function tearDown(): void
    {
        Coordinator::reset();
        parent::tearDown();
    }

    public function testSequentialMigrationsFromSchemaTwoReachCurrent(): void
    {
        $runner = new MigrationRunner(
            new DatabaseMigrationRepository($this->connection),
            new MysqlAdvisoryLock($this->connection),
        );
        $runner->run([
            new BaselineMigration($this->connection),
            new QueueTablesMigration($this->connection),
        ]);
        self::assertSame(2, $runner->currentVersion());
        self::assertFalse(Schema::hasColumn($this->connection, Schema::JOBS, 'cancel_requested'));

        $upgraded = Coordinator::bootForTesting(['driver' => 'mysql'], connection: $this->connection);
        self::assertSame(SchemaOwner::CURRENT_VERSION, $upgraded->migrations()->currentVersion());
        self::assertTrue(Schema::hasColumn($this->connection, Schema::JOBS, 'cancel_requested'));
        self::assertTrue(Schema::hasColumn($this->connection, Schema::WORKERS, 'runtime_generation'));
    }
}
