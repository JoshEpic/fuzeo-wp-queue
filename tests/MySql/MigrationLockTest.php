<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\MySql;

use Fuzeo\Queue\Persistence\MysqlAdvisoryLock;
use Fuzeo\Queue\Persistence\PdoConnection;

final class MigrationLockTest extends MysqlTestCase
{
    public function testSecondConnectionWaitsThenFailsToAcquireHeldLock(): void
    {
        $first = new MysqlAdvisoryLock($this->connection, 'fuzeo_queue_schema', 1);
        self::assertTrue($first->acquire(1));

        $secondConnection = PdoConnection::fromDsn($this->dsn, $this->user, $this->password, 'wp_');
        $second = new MysqlAdvisoryLock($secondConnection, 'fuzeo_queue_schema', 1);
        self::assertFalse($second->acquire(1));

        $first->release();
        self::assertTrue($second->acquire(1));
        $second->release();
    }
}
