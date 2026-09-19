<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Drivers;

use Fuzeo\Queue\Jobs\Envelope;

/**
 * Queue backend contract. Semantics are at-least-once.
 *
 * enqueue: persist a job and make it eligible at available_at.
 * reserve: atomically acquire execution rights for one eligible job.
 * acknowledge: complete only if the reservation token still owns the job.
 * release: drop the reservation and optionally delay eligibility.
 * fail: record terminal failure for the owning reservation.
 * extendLease: extend visibility only for the current valid reservation holder.
 */
interface QueueDriver
{
    public function enqueue(Envelope $envelope): EnqueuedJob;

    public function reserve(ReserveRequest $request): ?Reservation;

    public function acknowledge(Reservation $reservation): void;

    public function release(Reservation $reservation, ReleaseOptions $options): void;

    public function fail(Reservation $reservation, Failure $failure): void;

    public function extendLease(Reservation $reservation, \DateInterval $extension): Reservation;

    public function size(string $queue): int;

    public function health(): DriverHealth;

    public function capabilities(): DriverCapabilities;
}
