<?php

declare(strict_types=1);

namespace Acme\QueueDemo;

use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\Handler;

final class ProcessOrderHandler implements Handler
{
    public function handle(Envelope $envelope): void
    {
        $orderId = (int) ($envelope->payload['order_id'] ?? 0);
        unset($orderId);
    }
}
