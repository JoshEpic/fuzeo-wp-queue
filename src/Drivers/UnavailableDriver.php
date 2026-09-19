<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Drivers;

use Fuzeo\Queue\Exceptions\DriverException;
use Fuzeo\Queue\Jobs\Envelope;

/**
 * Default production driver until Phase 2. Dispatch is rejected rather than faked as durable.
 */
final class UnavailableDriver implements QueueDriver
{
    public function enqueue(Envelope $envelope): EnqueuedJob
    {
        throw $this->unavailable();
    }

    public function reserve(ReserveRequest $request): ?Reservation
    {
        throw $this->unavailable();
    }

    public function acknowledge(Reservation $reservation): void
    {
        throw $this->unavailable();
    }

    public function release(Reservation $reservation, ReleaseOptions $options): void
    {
        throw $this->unavailable();
    }

    public function fail(Reservation $reservation, Failure $failure): void
    {
        throw $this->unavailable();
    }

    public function extendLease(Reservation $reservation, \DateInterval $extension): Reservation
    {
        throw $this->unavailable();
    }

    public function size(string $queue): int
    {
        throw $this->unavailable();
    }

    public function health(): DriverHealth
    {
        return new DriverHealth(false, 'unavailable', [], 'No durable driver is configured. Phase 1 ships MemoryDriver for tests only.');
    }

    public function capabilities(): DriverCapabilities
    {
        return new DriverCapabilities();
    }

    private function unavailable(): DriverException
    {
        return new DriverException(
            'Fuzeo Queue has no durable driver in Phase 1. Use Queue::fake() or the memory driver in tests. The MySQL driver arrives in Phase 2.'
        );
    }
}
