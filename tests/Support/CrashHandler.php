<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Support;

use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\Handler;

final class CrashHandler implements Handler
{
    public function handle(Envelope $envelope): void
    {
        unset($envelope);
        $pid = getmypid();
        if (function_exists('posix_kill') && defined('SIGKILL') && is_int($pid)) {
            posix_kill($pid, SIGKILL);
        }
        exit(99);
    }
}
