<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Support;

use Fuzeo\Queue\Exceptions\RetryableException;
use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\Handler;

final class FailUntilHandler implements Handler
{
    public static int $failUntilAttempt = 2;

    public static function reset(): void
    {
        self::$failUntilAttempt = 2;
    }

    public function handle(Envelope $envelope): void
    {
        if ($envelope->attempt < self::$failUntilAttempt) {
            throw new RetryableException('not yet');
        }
    }
}
