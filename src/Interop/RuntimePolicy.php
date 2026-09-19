<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

/**
 * Dispatch-time selection. Fallback never moves an already-queued Fuzeo job.
 */
enum RuntimePolicy: string
{
    case PreferQueue = 'prefer_queue';
    case RequireQueue = 'require_queue';
}
