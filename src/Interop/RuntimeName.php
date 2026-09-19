<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

enum RuntimeName: string
{
    case FuzeoQueue = 'fuzeo_queue';
    case ActionScheduler = 'action_scheduler';
    case Unavailable = 'unavailable';
    case Fake = 'fake';
}
