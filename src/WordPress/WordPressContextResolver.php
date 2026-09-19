<?php

declare(strict_types=1);

namespace Fuzeo\Queue\WordPress;

use Fuzeo\Queue\Contracts\ExecutionContextResolver;
use Fuzeo\Queue\Exceptions\QueueException;
use Fuzeo\Queue\Jobs\ExecutionContext;

final class WordPressContextResolver implements ExecutionContextResolver
{
    public function current(): ExecutionContext
    {
        $siteId = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1;
        $networkId = 1;
        if (function_exists('get_current_network_id')) {
            $networkId = (int) get_current_network_id();
        } elseif (isset($GLOBALS['current_site']) && is_object($GLOBALS['current_site']) && isset($GLOBALS['current_site']->id)) {
            $networkId = (int) $GLOBALS['current_site']->id;
        }

        if ($networkId < 1) {
            $networkId = 1;
        }

        if ($siteId < 1) {
            throw new QueueException('WordPress reported an invalid site_id of ' . $siteId . '.');
        }

        return ExecutionContext::site($networkId, $siteId);
    }
}
