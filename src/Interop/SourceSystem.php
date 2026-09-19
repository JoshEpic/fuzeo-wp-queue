<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

enum SourceSystem: string
{
    case ActionScheduler = 'action_scheduler';
    case WpCron = 'wp_cron';
}
