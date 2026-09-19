<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Worker;

use Fuzeo\Queue\Exceptions\JobTimeoutException;

final class TimeoutGuard
{
    private bool $armed = false;

    public function arm(int $seconds, callable $onTimeout): void
    {
        $this->disarm();
        if ($seconds < 1 || !function_exists('pcntl_alarm') || !function_exists('pcntl_signal')) {
            return;
        }
        if (getenv('FUZEO_QUEUE_DISABLE_ALARMS') === '1') {
            return;
        }
        pcntl_signal(SIGALRM, function () use ($onTimeout): void {
            $this->armed = false;
            $onTimeout();
        });
        pcntl_alarm($seconds);
        $this->armed = true;
    }

    public function disarm(): void
    {
        if (function_exists('pcntl_alarm')) {
            pcntl_alarm(0);
        }
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGALRM, SIG_IGN);
        }
        $this->armed = false;
    }

    public function throwTimeout(): never
    {
        throw new JobTimeoutException('Job exceeded the configured timeout.');
    }

    public function isArmed(): bool
    {
        return $this->armed;
    }

    public static function available(): bool
    {
        return function_exists('pcntl_alarm') && function_exists('pcntl_signal');
    }
}
