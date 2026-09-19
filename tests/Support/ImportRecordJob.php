<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Support;

use Fuzeo\Queue\Jobs\Job;

final class ImportRecordJob implements Job
{
    public function __construct(private readonly string $recordId)
    {
    }

    public static function type(): string
    {
        return 'acme.import_record';
    }

    public static function schemaVersion(): int
    {
        return 2;
    }

    public function payload(): array
    {
        return ['record_id' => $this->recordId];
    }
}
