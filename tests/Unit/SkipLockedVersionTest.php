<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Unit;

use Fuzeo\Queue\Persistence\PdoConnection;
use PHPUnit\Framework\TestCase;

final class SkipLockedVersionTest extends TestCase
{
    public function testMysqlAndMariadbBaselines(): void
    {
        self::assertTrue(PdoConnection::detectSkipLockedFromVersion('8.0.36'));
        self::assertFalse(PdoConnection::detectSkipLockedFromVersion('5.7.44'));
        self::assertTrue(PdoConnection::detectSkipLockedFromVersion('10.11.8-MariaDB'));
        self::assertFalse(PdoConnection::detectSkipLockedFromVersion('10.5.22-MariaDB'));
    }
}
