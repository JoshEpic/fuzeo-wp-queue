<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Worker;

use Fuzeo\Queue\Exceptions\SiteUnavailableException;
use Fuzeo\Queue\Jobs\ExecutionContext;
use Fuzeo\Queue\Jobs\ExecutionScope;

final class MappedSiteSwitcher implements SiteSwitcher
{
    /**
     * @param array<int, true> $sites
     */
    public function __construct(private readonly array $sites)
    {
    }

    public function run(ExecutionContext $context, callable $callback): mixed
    {
        if ($context->scope === ExecutionScope::Network) {
            return $callback();
        }
        if (!isset($this->sites[$context->siteId])) {
            throw new SiteUnavailableException(
                'Site ' . $context->siteId . ' no longer exists. The job will not run against another blog.'
            );
        }

        return $callback();
    }
}
