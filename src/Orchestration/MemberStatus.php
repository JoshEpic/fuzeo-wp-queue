<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Orchestration;

enum MemberStatus: string
{
    case Waiting = 'waiting';
    case Dispatched = 'dispatched';
    case Completed = 'completed';
    case Dead = 'dead';
    case Cancelled = 'cancelled';
    case UniqueConflict = 'unique_conflict';
}
