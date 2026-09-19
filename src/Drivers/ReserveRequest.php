<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Drivers;

use Fuzeo\Queue\Jobs\QueueName;

final class ReserveRequest
{
    public function __construct(
        public readonly string $queue,
        public readonly string $workerId,
        public readonly int $leaseSeconds = 60,
        public readonly int $blockSeconds = 0,
    ) {
        QueueName::assertValid($this->queue);
        if ($this->workerId === '') {
            throw new \Fuzeo\Queue\Exceptions\QueueException('worker_id is required to reserve work.');
        }
        if ($this->leaseSeconds < 1) {
            throw new \Fuzeo\Queue\Exceptions\QueueException('lease_seconds must be positive.');
        }
        if ($this->blockSeconds < 0) {
            throw new \Fuzeo\Queue\Exceptions\QueueException('block_seconds cannot be negative.');
        }
    }
}
