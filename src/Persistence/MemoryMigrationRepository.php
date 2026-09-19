<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Persistence;

final class MemoryMigrationRepository implements MigrationRepository
{
    public function __construct(private int $version = 0)
    {
    }

    public function currentVersion(): int
    {
        return $this->version;
    }

    public function record(int $version): void
    {
        $this->version = $version;
    }
}
