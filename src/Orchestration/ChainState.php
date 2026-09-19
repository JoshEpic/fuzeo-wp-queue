<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Orchestration;

enum ChainState: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
