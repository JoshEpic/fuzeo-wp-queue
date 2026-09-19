<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Worker;

final class SignalListener
{
    private bool $stopRequested = false;

    private bool $enabled = false;

    public function install(): void
    {
        if ($this->enabled) {
            return;
        }
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            return;
        }
        pcntl_async_signals(true);
        $handler = function (): void {
            $this->stopRequested = true;
        };
        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
        $this->enabled = true;
    }

    public function requestStop(): void
    {
        $this->stopRequested = true;
    }

    public function shouldStop(): bool
    {
        return $this->stopRequested;
    }

    public function pcntlAvailable(): bool
    {
        return function_exists('pcntl_signal');
    }
}
