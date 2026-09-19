<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Jobs;

/**
 * Optional job contract. Keep this small; prefer RequiresPersistentWorker when that is the only need.
 */
interface RequiresExecutionCapabilities
{
    /**
     * Known keys: persistent_worker, long_running.
     *
     * @return list<string>
     */
    public function requiredCapabilities(): array;
}
