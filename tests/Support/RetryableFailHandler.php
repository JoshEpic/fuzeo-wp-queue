<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Support;

use Fuzeo\Queue\Exceptions\RetryableException;
use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\Handler;

final class RetryableFailHandler implements Handler
{
    public function handle(Envelope $envelope): void
    {
        unset($envelope);
        throw new RetryableException('temporary failure');
    }
}
