<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Drivers;

use Fuzeo\Queue\Exceptions\QueueException;
use Fuzeo\Queue\Support\Ulid;

final class ReservationToken
{
    public function __construct(public readonly string $value)
    {
        if (!Ulid::isValid($value)) {
            throw new QueueException('Reservation token must be a ULID.');
        }
    }

    public static function generate(): self
    {
        return new self(Ulid::generate());
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
