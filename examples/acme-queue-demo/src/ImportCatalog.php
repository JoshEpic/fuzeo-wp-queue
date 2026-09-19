<?php

declare(strict_types=1);

namespace Acme\QueueDemo;

use Fuzeo\Queue\Jobs\Job;
use Fuzeo\Queue\Jobs\RequiresPersistentWorker;

final class ImportCatalog implements Job, RequiresPersistentWorker
{
    public function __construct(private readonly int $batchId)
    {
    }

    public static function type(): string
    {
        return 'acme.demo_import_catalog';
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
