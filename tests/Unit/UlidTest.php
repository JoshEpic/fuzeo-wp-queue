<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Unit;

use Fuzeo\Queue\Support\Ulid;
use PHPUnit\Framework\TestCase;

final class UlidTest extends TestCase
{
    public function testGeneratesValidTwentySixCharacterId(): void
    {
        $id = Ulid::generate();
        self::assertTrue(Ulid::isValid($id));
        self::assertSame(26, strlen($id));
    }

    public function testIsSortableByTime(): void
    {
        $earlier = Ulid::generate(1_700_000_000_000);
        $later = Ulid::generate(1_700_000_000_001);
        self::assertTrue($earlier < $later);
    }

    public function testRejectsInvalidValues(): void
    {
        self::assertFalse(Ulid::isValid('not-a-ulid'));
        self::assertFalse(Ulid::isValid(strtolower(Ulid::generate())));
    }
}
