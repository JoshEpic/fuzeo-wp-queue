<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\MySql;

use Fuzeo\Queue\Persistence\Schema;
use Fuzeo\Queue\Persistence\SchemaOwner;
use Fuzeo\Queue\Runtime\Coordinator;

final class Phase11SchemaTest extends MysqlTestCase
{
    protected function tearDown(): void
    {
        Coordinator::reset();
        parent::tearDown();
    }

    public function testSchemaSevenCreatesInteropTable(): void
    {
        $upgraded = Coordinator::bootForTesting(['driver' => 'mysql'], connection: $this->connection);
        self::assertSame(SchemaOwner::CURRENT_VERSION, $upgraded->migrations()->currentVersion());
        self::assertSame(7, SchemaOwner::CURRENT_VERSION);
        $row = $this->connection->selectOne(
            'SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->connection->prefix() . Schema::MIGRATIONS]
        );
        self::assertNotNull($row);
    }
}
