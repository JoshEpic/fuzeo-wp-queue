<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Schedule;

final class MappedSitePresence implements SitePresence
{
    /**
     * @param array<string, bool> $sites keyed by "network:site"
     */
    public function __construct(private array $sites)
    {
    }

    public function exists(int $networkId, int $siteId): bool
    {
        if ($siteId === 0) {
            return true;
        }

        return $this->sites[$networkId . ':' . $siteId] ?? false;
    }
}
