<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Schedule;

enum ScheduleExpressionType: string
{
    case Interval = 'interval';

    case Daily = 'daily';

    case Cron = 'cron';
}
