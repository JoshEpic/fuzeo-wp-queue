<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Drivers;

interface StatusAware
{
    /**
     * @return array<string, int>
     */
    public function countsByState(): array;

    public function retryingCount(): int;
}
