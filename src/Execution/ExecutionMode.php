<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Execution;

enum ExecutionMode: string
{
    case Persistent = 'persistent';
    case CronCli = 'cron_cli';
    case WordPressCompat = 'wordpress_compat';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Persistent => 'Persistent Worker',
            self::CronCli => 'External Cron',
            self::WordPressCompat => 'WordPress Compatibility Mode',
            self::None => 'None',
        };
    }
}
