<?php

declare(strict_types=1);

namespace Acme\QueueDemo;

use Fuzeo\Queue\Jobs\Job;

final class ProcessOrder implements Job
{
    public function __construct(private readonly int $orderId)
    {
    }

    public static function type(): string
    {
        return 'acme.demo_process_order';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }

    public function payload(): array
    {
        return ['order_id' => $this->orderId];
    }
}
