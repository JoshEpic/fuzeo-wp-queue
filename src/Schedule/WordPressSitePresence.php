<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Schedule;

final class WordPressSitePresence implements SitePresence
{
    public function exists(int $networkId, int $siteId): bool
    {
        unset($networkId);
        if ($siteId === 0) {
            return true;
        }
        if (function_exists('get_site')) {
            $site = get_site($siteId);

            return $site !== null;
        }
        if (function_exists('get_blog_details')) {
            $details = get_blog_details($siteId);

            return $details !== false && $details !== null;
        }

        return true;
    }
}
