<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Jobs;

/**
 * Marker: this job must not run in WordPress compatibility mode.
 */
interface RequiresPersistentWorker
{
}
