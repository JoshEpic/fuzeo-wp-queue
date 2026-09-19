<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Jobs;

/**
 * Prevents another equivalent job from being queued while this logical identity is active.
 *
 * Uniqueness is dispatch control, not handler idempotency.
 */
interface UniqueJob
{
    /**
     * Stable key such as "product:123". Scoped internally by origin, site, and job type.
     */
    public function uniqueKey(): string;
}
