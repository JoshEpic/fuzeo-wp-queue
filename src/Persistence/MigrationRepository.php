<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Persistence;

interface MigrationRepository
{
    public function currentVersion(): int;

    public function record(int $version): void;
}
