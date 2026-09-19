<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Support;

use Fuzeo\Queue\Exceptions\RetryableException;
use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\Handler;

final class FlakyHandler implements Handler
{
    public function handle(Envelope $envelope): void
    {
        if ($envelope->attempt < 2) {
            throw new RetryableException('first attempt failed');
        }
    }
}
