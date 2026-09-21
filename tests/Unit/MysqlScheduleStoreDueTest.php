<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Unit;

use Fuzeo\Queue\Schedule\MysqlScheduleStore;
use Fuzeo\Queue\Support\FrozenClock;
use Fuzeo\Queue\Tests\Support\CapturingConnection;
use PHPUnit\Framework\TestCase;

final class MysqlScheduleStoreDueTest extends TestCase
{
    public function testDueQueryTreatsEmptyBlockedReasonAsUnblocked(): void
    {
        $connection = new CapturingConnection();
        $store = new MysqlScheduleStore(
            $connection,
            new FrozenClock(new \DateTimeImmutable('2026-09-21 22:45:00', new \DateTimeZone('UTC'))),
        );
        $store->due(new \DateTimeImmutable('2026-09-21 22:45:00', new \DateTimeZone('UTC')));
        self::assertStringContainsString(
            '(`blocked_reason` IS NULL OR `blocked_reason` = \'\')',
            $connection->lastSelectSql
        );
        self::assertStringContainsString('`next_run_at` <= ?', $connection->lastSelectSql);
    }
}
