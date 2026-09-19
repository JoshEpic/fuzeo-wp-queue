<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Locks;

interface DistributedLock
{
    public function acquire(string $name, string $owner, int $ttlSeconds): bool;

    public function release(string $name, string $owner): bool;

    public function extend(string $name, string $owner, int $ttlSeconds): bool;
}
