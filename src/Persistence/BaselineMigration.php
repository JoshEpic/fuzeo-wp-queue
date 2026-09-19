<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Persistence;

/**
 * Baseline placeholder. Phase 1 does not create queue tables.
 */
final class BaselineMigration implements Migration
{
    public function version(): int
    {
        return 1;
    }

    public function description(): string
    {
        return 'Record Fuzeo Queue schema ownership. Queue tables are introduced in Phase 2.';
    }

    public function up(): void
    {
    }
}
