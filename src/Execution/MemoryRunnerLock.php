<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Execution;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Locks\DistributedLock;
use Fuzeo\Queue\Support\SystemClock;

final class MemoryRunnerLock implements DistributedLock
{
    /** @var array<string, array{owner: string, until: int}> */
    private array $locks = [];

    public function __construct(private readonly Clock $clock = new SystemClock())
    {
    }

    public function acquire(string $name, string $owner, int $ttlSeconds): bool
    {
        $now = $this->clock->now()->getTimestamp();
        $held = $this->locks[$name] ?? null;
        if ($held !== null && $held['until'] > $now && $held['owner'] !== $owner) {
            return false;
        }
        $this->locks[$name] = ['owner' => $owner, 'until' => $now + max(1, $ttlSeconds)];

        return true;
    }

    public function release(string $name, string $owner): bool
    {
        $held = $this->locks[$name] ?? null;
        if ($held === null || $held['owner'] !== $owner) {
            return false;
        }
        unset($this->locks[$name]);

        return true;
    }

    public function extend(string $name, string $owner, int $ttlSeconds): bool
    {
        $held = $this->locks[$name] ?? null;
        if ($held === null || $held['owner'] !== $owner) {
            return false;
        }
        $this->locks[$name] = ['owner' => $owner, 'until' => $this->clock->now()->getTimestamp() + max(1, $ttlSeconds)];

        return true;
    }
}
