<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Persistence;

use Fuzeo\Queue\Exceptions\SchemaException;

final class MigrationRunner
{
    public function __construct(
        private readonly MigrationRepository $repository,
        private readonly MigrationLock $lock,
    ) {
    }

    /**
     * @param list<Migration> $migrations
     */
    public function run(array $migrations): MigrationResult
    {
        $this->assertSequential($migrations);

        if (!$this->lock->acquire()) {
            return new MigrationResult($this->repository->currentVersion(), $this->repository->currentVersion(), [], true);
        }

        try {
            $from = $this->repository->currentVersion();
            $applied = [];
            foreach ($migrations as $migration) {
                if ($migration->version() <= $from) {
                    continue;
                }
                $migration->up();
                $this->repository->record($migration->version());
                $applied[] = $migration->version();
            }

            return new MigrationResult($from, $this->repository->currentVersion(), $applied);
        } finally {
            $this->lock->release();
        }
    }

    /**
     * @param list<Migration> $migrations
     */
    private function assertSequential(array $migrations): void
    {
        $expected = 1;
        foreach ($migrations as $migration) {
            if ($migration->version() !== $expected) {
                throw new SchemaException('Migrations must be sequential starting at 1. Found version ' . $migration->version() . '.');
            }
            $expected++;
        }
    }
}
