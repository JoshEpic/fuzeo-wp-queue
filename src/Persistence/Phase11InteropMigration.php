<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Persistence;

final class Phase11InteropMigration implements Migration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function version(): int
    {
        return 7;
    }

    public function description(): string
    {
        return 'Create Fuzeo Queue interoperability migration history table.';
    }

    public function up(): void
    {
        InteropSchema::ensure($this->connection);
    }
}
