<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Support;

use Fuzeo\Queue\Jobs\Job;

final class RecordJob implements Job
{
    public function __construct(private readonly string $path, private readonly string $token = 'ok')
    {
    }

    public static function type(): string
    {
        return 'acme.record_job';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }

    public function payload(): array
    {
        return ['path' => $this->path, 'token' => $this->token];
    }
}
