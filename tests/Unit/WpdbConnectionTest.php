<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Unit;

use Fuzeo\Queue\Persistence\WpdbConnection;
use Fuzeo\Queue\Tests\Support\RecordingWpdb;
use PHPUnit\Framework\TestCase;

final class WpdbConnectionTest extends TestCase
{
    public function testNullBindingsBecomeUnquotedSqlNull(): void
    {
        $wpdb = new RecordingWpdb();
        $connection = new WpdbConnection($wpdb);
        $connection->execute('INSERT INTO t (a, b, c) VALUES (?, ?, ?)', ['x', null, 2]);
        self::assertSame('INSERT INTO t (a, b, c) VALUES (%s, NULL, %d)', $wpdb->lastPrepareSql);
        self::assertSame(['x', 2], $wpdb->lastPrepareArgs);
        self::assertSame('INSERT INTO t (a, b, c) VALUES (%s, NULL, %d)', $wpdb->lastQuery);
    }

    public function testEmptyStringIsNotSqlNull(): void
    {
        $wpdb = new RecordingWpdb();
        $connection = new WpdbConnection($wpdb);
        $connection->execute('UPDATE t SET a = ?', ['']);
        self::assertSame('UPDATE t SET a = %s', $wpdb->lastPrepareSql);
        self::assertSame([''], $wpdb->lastPrepareArgs);
    }

    public function testAllNullBindingsSkipWpdbPrepare(): void
    {
        $wpdb = new RecordingWpdb();
        $connection = new WpdbConnection($wpdb);
        $connection->execute('UPDATE t SET a = ?, b = ?', [null, null]);
        self::assertSame('', $wpdb->lastPrepareSql);
        self::assertSame([], $wpdb->lastPrepareArgs);
        self::assertSame('UPDATE t SET a = NULL, b = NULL', $wpdb->lastQuery);
    }
}
