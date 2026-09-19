<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Support;

use Fuzeo\Queue\Jobs\Handler;
use Fuzeo\Queue\Jobs\Envelope;

final class ProcessOrderHandler implements Handler
{
    public function handle(Envelope $envelope): void
    {
    }
}
