<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Drivers;

interface Reconnectable
{
    public function ping(): bool;

    public function reconnect(): void;
}
