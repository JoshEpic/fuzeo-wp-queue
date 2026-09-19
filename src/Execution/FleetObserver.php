<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Execution;

use Fuzeo\Queue\Config\Config;
use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Drivers\ProvidesWorkerStore;
use Fuzeo\Queue\Drivers\QueueDriver;
use Fuzeo\Queue\Support\Dates;
use Fuzeo\Queue\Support\SystemClock;
use Fuzeo\Queue\Worker\WorkerStatus;

final class FleetObserver
{
    public function __construct(
        private readonly QueueDriver $driver,
        private readonly Config $config,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function hasHealthyPersistentWorkers(): bool
    {
        return $this->countPersistent($this->config->staleWorkerSeconds) > 0;
    }

    /**
     * Compat may start only after the longer grace window so a late heartbeat does not flap.
     */
    public function compatMayProcess(): bool
    {
        return $this->countPersistent($this->config->compatibilityStaleWorkerGrace) === 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function workersByType(): array
    {
        if (!$this->driver instanceof ProvidesWorkerStore) {
            return [];
        }
        $out = [];
        foreach ($this->driver->workerStore()->all() as $row) {
            $type = (string) ($row['process_type'] ?? ProcessType::Persistent->value);
            $status = (string) ($row['status'] ?? '');
            $stale = $this->driver->workerStore()->isStale($row, $this->config->staleWorkerSeconds);
            $out[] = [
                'worker_id' => (string) ($row['worker_id'] ?? ''),
                'process_type' => $type,
                'status' => $status,
                'stale' => $stale,
                'health' => $status === WorkerStatus::Stopped->value ? 'stopped' : ($stale ? 'stale' : 'live'),
                'last_heartbeat_at' => (string) ($row['last_heartbeat_at'] ?? ''),
            ];
        }

        return $out;
    }

    public function lastCronCliAt(): ?\DateTimeImmutable
    {
        if (!$this->driver instanceof ProvidesWorkerStore) {
            return null;
        }
        $latest = null;
        foreach ($this->driver->workerStore()->all() as $row) {
            if ((string) ($row['process_type'] ?? '') !== ProcessType::CronCli->value) {
                continue;
            }
            $at = $this->heartbeat($row);
            if ($at !== null && ($latest === null || $at > $latest)) {
                $latest = $at;
            }
        }

        return $latest;
    }

    private function countPersistent(int $maxAgeSeconds): int
    {
        if (!$this->driver instanceof ProvidesWorkerStore) {
            return 0;
        }
        $now = $this->clock->now()->getTimestamp();
        $count = 0;
        foreach ($this->driver->workerStore()->all() as $row) {
            $type = (string) ($row['process_type'] ?? ProcessType::Persistent->value);
            if ($type !== ProcessType::Persistent->value) {
                continue;
            }
            if ((string) ($row['status'] ?? '') === WorkerStatus::Stopped->value) {
                continue;
            }
            $at = $this->heartbeat($row);
            if ($at === null) {
                continue;
            }
            if (($now - $at->getTimestamp()) <= $maxAgeSeconds) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function heartbeat(array $row): ?\DateTimeImmutable
    {
        $raw = $row['last_heartbeat_at'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            if (str_contains($raw, 'T')) {
                return Dates::fromAtom($raw);
            }

            return new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }
}
