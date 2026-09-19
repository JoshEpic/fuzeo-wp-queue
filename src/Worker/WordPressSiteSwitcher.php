<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Worker;

use Fuzeo\Queue\Exceptions\SiteUnavailableException;
use Fuzeo\Queue\Jobs\ExecutionContext;
use Fuzeo\Queue\Jobs\ExecutionScope;

final class WordPressSiteSwitcher implements SiteSwitcher
{
    public function run(ExecutionContext $context, callable $callback): mixed
    {
        if ($context->scope === ExecutionScope::Network) {
            return $callback();
        }

        if (!function_exists('switch_to_blog') || !function_exists('restore_current_blog')) {
            throw new SiteUnavailableException('WordPress blog switching is unavailable.');
        }

        if (!$this->siteExists($context->siteId)) {
            throw new SiteUnavailableException(
                'Site ' . $context->siteId . ' no longer exists. The job will not run against another blog.'
            );
        }

        switch_to_blog($context->siteId);
        try {
            return $callback();
        } finally {
            restore_current_blog();
        }
    }

    private function siteExists(int $siteId): bool
    {
        if (function_exists('get_site')) {
            $site = get_site($siteId);

            return $site !== null && $site !== false;
        }
        if (function_exists('get_blog_details')) {
            $details = get_blog_details($siteId);

            return $details !== false && $details !== null;
        }

        return $siteId === (function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1);
    }
}
