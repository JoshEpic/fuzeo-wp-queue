<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Jobs;

/**
 * Handler that can inspect cooperative cancellation.
 */
interface ContextualHandler
{
    public function handleContext(JobContext $context): void;
}
