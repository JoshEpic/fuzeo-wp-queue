<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Support;

use Fuzeo\Queue\Jobs\Job;
use Fuzeo\Queue\Jobs\RequiresPersistentWorker;

final class ImportCatalogJob implements Job, RequiresPersistentWorker
{
    public function __construct(private readonly int $batchId = 1)
    {
    }

    public static function type(): string
    {
        return 'acme.import_catalog';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }

    public function payload(): array
    {
        return ['batch_id' => $this->batchId];
    }
}
