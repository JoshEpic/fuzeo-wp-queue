<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Orchestration;

enum BatchState: string
{
    case Creating = 'creating';
    case Active = 'active';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
