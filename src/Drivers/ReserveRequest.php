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
        public readonly ?string $executionClass = null,
        public readonly ?int $maxTimeoutSeconds = null,
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
        if (
            $this->executionClass !== null
            && !in_array($this->executionClass, ['standard', 'persistent'], true)
        ) {
            throw new \Fuzeo\Queue\Exceptions\QueueException('execution_class filter must be standard or persistent.');
        }
        if ($this->maxTimeoutSeconds !== null && $this->maxTimeoutSeconds < 1) {
            throw new \Fuzeo\Queue\Exceptions\QueueException('max_timeout_seconds must be positive when set.');
        }
    }
}
