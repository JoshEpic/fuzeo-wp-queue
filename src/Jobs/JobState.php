<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Jobs;

enum JobState: string
{
    case Pending = 'pending';
    case Reserved = 'reserved';
    case Completed = 'completed';
    case Failed = 'failed';
    case Dead = 'dead';
    case Cancelled = 'cancelled';
}
