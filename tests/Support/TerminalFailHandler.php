<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Support;

use Fuzeo\Queue\Exceptions\TerminalException;
use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\Handler;

final class TerminalFailHandler implements Handler
{
    public function handle(Envelope $envelope): void
    {
        unset($envelope);
        throw new TerminalException('malformed payload');
    }
}
