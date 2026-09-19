<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Support;

use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\Handler;

final class FileRecordingHandler implements Handler
{
    public function handle(Envelope $envelope): void
    {
        $path = $envelope->payload['path'] ?? null;
        if (!is_string($path) || $path === '') {
            return;
        }
        file_put_contents(
            $path,
            $envelope->jobId . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}
