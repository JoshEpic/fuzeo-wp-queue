<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\MySql;

use Fuzeo\Queue\Persistence\SchemaOwner;
use Fuzeo\Queue\Runtime\Coordinator;

final class SchemaUpgradeTest extends MysqlTestCase
{
    protected function tearDown(): void
    {
        Coordinator::reset();
        parent::tearDown();
    }

    public function testSequentialMigrationsFromSchemaTwoReachSix(): void
    {
        $manager = Coordinator::bootForTesting(['driver' => 'mysql'], connection: $this->connection);
        self::assertSame(SchemaOwner::CURRENT_VERSION, $manager->migrations()->currentVersion());
        $this->connection->execute(
            'UPDATE `' . $this->connection->prefix() . 'fuzeo_queue_meta` SET `meta_value` = ? WHERE `meta_key` = ?',
            ['2', 'schema_version']
        );
        Coordinator::reset();
        $upgraded = Coordinator::bootForTesting(['driver' => 'mysql'], connection: $this->connection);
        self::assertSame(6, $upgraded->migrations()->currentVersion());
    }
}
