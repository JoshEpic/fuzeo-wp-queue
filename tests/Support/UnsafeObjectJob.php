<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Support;

use Fuzeo\Queue\Jobs\Job;

final class UnsafeObjectJob implements Job
{
    public static function type(): string
    {
        return 'acme.unsafe_object';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }

    public function payload(): array
    {
        $order = new \stdClass();
        $order->id = 15;

        return ['order' => $order];
    }
}
