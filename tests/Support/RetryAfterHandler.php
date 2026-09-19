<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Support;

use Fuzeo\Queue\Exceptions\RetryAfterException;
use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\Handler;

final class RetryAfterHandler implements Handler
{
    public function handle(Envelope $envelope): void
    {
        unset($envelope);
        throw RetryAfterException::after(120, 'rate limited');
    }
}
