<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Drivers;

use Fuzeo\Queue\Schedule\ScheduleStore;

interface ProvidesScheduleStore
{
    public function scheduleStore(): ScheduleStore;
}
