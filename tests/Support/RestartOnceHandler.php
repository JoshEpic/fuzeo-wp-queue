<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Support;

use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\Handler;
use Fuzeo\Queue\Operations\Operator;
use Fuzeo\Queue\Runtime\Coordinator;

final class RestartOnceHandler implements Handler
{
    public static int $handled = 0;

    public static function reset(): void
    {
        self::$handled = 0;
    }

    public function handle(Envelope $envelope): void
    {
        unset($envelope);
        self::$handled++;
        if (self::$handled === 3) {
            Coordinator::get()->operations()->requestRestart(Operator::cli());
        }
    }
}
