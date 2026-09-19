<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Unit;

use Fuzeo\Queue\Persistence\BaselineMigration;
use Fuzeo\Queue\Persistence\MemoryMigrationLock;
use Fuzeo\Queue\Persistence\MemoryMigrationRepository;
use Fuzeo\Queue\Persistence\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class MigrationRunnerTest extends TestCase
{
    public function testRunsOnceUnderLock(): void
    {
        $repo = new MemoryMigrationRepository();
        $lock = new MemoryMigrationLock();
        $runner = new MigrationRunner($repo, $lock);
        $first = $runner->run([new BaselineMigration()]);
        self::assertSame([1], $first->applied);
        $second = $runner->run([new BaselineMigration()]);
        self::assertSame([], $second->applied);
        self::assertSame(1, $repo->currentVersion());
    }

    public function testLockedOutDoesNotMigrate(): void
    {
        $lock = new MemoryMigrationLock();
        self::assertTrue($lock->acquire());
        $runner = new MigrationRunner(new MemoryMigrationRepository(), $lock);
        $result = $runner->run([new BaselineMigration()]);
        self::assertTrue($result->lockedOut);
    }
}
