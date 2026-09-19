<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Drivers;

interface CancelsJobs
{
    public function cancel(string $jobId): CancelResult;

    public function isCancellationRequested(string $jobId): bool;

    public function settleCancelled(Reservation $reservation): void;
}
