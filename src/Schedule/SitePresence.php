<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Schedule;

interface SitePresence
{
    public function exists(int $networkId, int $siteId): bool;
}
