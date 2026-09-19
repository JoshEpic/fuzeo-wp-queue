<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Inspection;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Drivers\Memory\MemoryDriver;
use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\JobState;
use Fuzeo\Queue\Support\SystemClock;

final class MemoryJobCatalog implements JobCatalog
{
    public function __construct(
        private readonly MemoryDriver $driver,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function inspect(string $jobId): JobInspection
    {
        $envelope = $this->driver->job($jobId);
        $reservation = $this->driver->reservation($jobId);

        return new JobInspection(
            $envelope,
            $reservation?->workerId,
            $reservation?->reservedAt,
            $reservation?->leaseExpiresAt,
            $this->driver->isCancellationRequested($jobId),
        );
    }

    public function list(JobQuery $query): JobPage
    {
        $query = $query->bounded();
        if ($query->jobId !== null) {
            try {
                $item = $this->driver->job($query->jobId);
            } catch (\Throwable) {
                return new JobPage([], $query->limit, $query->offset, 0);
            }
            $matched = $this->matches($item, $query) ? [$item] : [];

            return new JobPage($matched, $query->limit, $query->offset, count($matched));
        }
        $matched = [];
        foreach ($this->driver->all() as $envelope) {
            if ($this->matches($envelope, $query)) {
                $matched[] = $envelope;
            }
        }
        usort($matched, static fn (Envelope $a, Envelope $b): int => $b->createdAt <=> $a->createdAt);
        $total = count($matched);
        $page = array_slice($matched, $query->offset, $query->limit);

        return new JobPage($page, $query->limit, $query->offset, $total);
    }

    public function queueSnapshots(?int $siteId = null): array
    {
        $now = $this->clock->now();
        /** @var array<string, array{pending: int, reserved: int, retrying: int, dead: int, cancelled: int, oldest: ?int}> $agg */
        $agg = [];
        foreach ($this->driver->all() as $envelope) {
            if ($siteId !== null && $envelope->context->siteId !== $siteId && $envelope->context->scope->value !== 'network') {
                continue;
            }
            $queue = $envelope->queue;
            $agg[$queue] ??= ['pending' => 0, 'reserved' => 0, 'retrying' => 0, 'dead' => 0, 'cancelled' => 0, 'oldest' => null];
            if ($envelope->state === JobState::Pending && $envelope->availableAt > $now) {
                $agg[$queue]['retrying']++;
            } elseif ($envelope->state === JobState::Pending) {
                $agg[$queue]['pending']++;
                $age = $now->getTimestamp() - $envelope->availableAt->getTimestamp();
                $agg[$queue]['oldest'] = $agg[$queue]['oldest'] === null ? $age : min($agg[$queue]['oldest'], $age);
            } elseif ($envelope->state === JobState::Reserved) {
                $agg[$queue]['reserved']++;
            } elseif ($envelope->state === JobState::Dead || $envelope->state === JobState::Failed) {
                $agg[$queue]['dead']++;
            } elseif ($envelope->state === JobState::Cancelled) {
                $agg[$queue]['cancelled']++;
            }
        }
        $out = [];
        ksort($agg);
        foreach ($agg as $queue => $row) {
            $out[] = new QueueSnapshot($queue, $row['pending'], $row['reserved'], $row['retrying'], $row['dead'], $row['cancelled'], $row['oldest']);
        }

        return $out;
    }

    public function oldestEligibleAgeSeconds(?string $queue = null, ?int $siteId = null): ?int
    {
        $now = $this->clock->now();
        $oldest = null;
        foreach ($this->driver->all() as $envelope) {
            if ($envelope->state !== JobState::Pending || $envelope->availableAt > $now) {
                continue;
            }
            if ($queue !== null && $envelope->queue !== $queue) {
                continue;
            }
            if ($siteId !== null && $envelope->context->siteId !== $siteId) {
                continue;
            }
            $age = $now->getTimestamp() - $envelope->availableAt->getTimestamp();
            $oldest = $oldest === null ? $age : min($oldest, $age);
        }

        return $oldest;
    }

    private function matches(Envelope $envelope, JobQuery $query): bool
    {
        if ($query->state !== null && $envelope->state !== $query->state) {
            return false;
        }
        if ($query->queue !== null && $envelope->queue !== $query->queue) {
            return false;
        }
        if ($query->jobType !== null && $envelope->jobType !== $query->jobType) {
            return false;
        }
        if ($query->origin !== null && $envelope->origin->package !== $query->origin) {
            return false;
        }
        if ($query->siteId !== null && $envelope->context->siteId !== $query->siteId) {
            return false;
        }
        if ($query->tag !== null && !in_array($query->tag, $envelope->tags, true)) {
            return false;
        }
        if ($query->createdAfter !== null && $envelope->createdAt < $query->createdAfter) {
            return false;
        }
        if ($query->createdBefore !== null && $envelope->createdAt > $query->createdBefore) {
            return false;
        }

        return true;
    }
}
