<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Schedule;

enum CatchUpPolicy: string
{
    /** Do not dispatch missed slots; wait for the next future occurrence unless the current slot is still open. */
    case Skip = 'skip';

    /** Dispatch only the most recent missed slot. */
    case Latest = 'latest';

    /** Dispatch missed slots up to the configured maximum, then skip the rest. */
    case All = 'all';
}
