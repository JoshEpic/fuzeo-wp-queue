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
    public function run(array $migrations, int $lockTimeout = 0): MigrationResult
    {
        $this->assertSequential($migrations);

        if (!$this->lock->acquire($lockTimeout)) {
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

    public function currentVersion(): int
    {
        return $this->repository->currentVersion();
    }

    /**
     * @param list<Migration> $migrations
     */
    public function pending(array $migrations): bool
    {
        $current = $this->repository->currentVersion();
        foreach ($migrations as $migration) {
            if ($migration->version() > $current) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<Migration> $migrations
     * @return array{current: int, target: int, required: bool}
     */
    public function status(array $migrations): array
    {
        $target = $this->repository->currentVersion();
        foreach ($migrations as $migration) {
            if ($migration->version() > $target) {
                $target = $migration->version();
            }
        }

        return [
            'current' => $this->repository->currentVersion(),
            'target' => $target,
            'required' => $this->pending($migrations),
        ];
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
