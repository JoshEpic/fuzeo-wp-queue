<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Drivers;

use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Support\Dates;

final class Reservation
{
    public function __construct(
        public readonly Envelope $envelope,
        public readonly ReservationToken $token,
        public readonly \DateTimeImmutable $reservedAt,
        public readonly \DateTimeImmutable $leaseExpiresAt,
        public readonly string $workerId,
    ) {
        if ($this->workerId === '' || strlen($this->workerId) > 128) {
            throw new \Fuzeo\Queue\Exceptions\QueueException('worker_id must be 1-128 characters.');
        }
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return Dates::utc($now) >= Dates::utc($this->leaseExpiresAt);
    }

    public function withLease(\DateTimeImmutable $leaseExpiresAt): self
    {
        return new self($this->envelope, $this->token, $this->reservedAt, Dates::utc($leaseExpiresAt), $this->workerId);
    }
}
