<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Support;

use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\Handler;

final class ProcessOrderHandler implements Handler
{
    public static int $handled = 0;

    /** @var list<string> */
    public static array $jobIds = [];

    public static function reset(): void
    {
        self::$handled = 0;
        self::$jobIds = [];
    }

    public function handle(Envelope $envelope): void
    {
        self::$handled++;
        self::$jobIds[] = $envelope->jobId;
    }
}
