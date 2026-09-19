<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Execution;

enum ProcessType: string
{
    case Persistent = 'persistent';
    case CronCli = 'cron_cli';
    case WordPressCompat = 'wordpress_compat';
}
