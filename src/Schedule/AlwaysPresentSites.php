<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Schedule;

final class AlwaysPresentSites implements SitePresence
{
    public function exists(int $networkId, int $siteId): bool
    {
        unset($networkId, $siteId);

        return true;
    }
}
