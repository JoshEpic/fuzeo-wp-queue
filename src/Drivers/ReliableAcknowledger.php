<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Drivers;

/**
 * ACK that must not be treated as success when persistence is unreachable.
 */
interface ReliableAcknowledger
{
    public function acknowledgeOrAmbiguous(Reservation $reservation): void;
}
