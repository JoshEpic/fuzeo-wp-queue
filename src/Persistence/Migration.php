<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Persistence;

interface Migration
{
    public function version(): int;

    public function description(): string;

    public function up(): void;
}
