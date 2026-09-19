<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Worker;

use Fuzeo\Queue\Exceptions\SiteUnavailableException;
use Fuzeo\Queue\Jobs\ExecutionContext;
use Fuzeo\Queue\Jobs\ExecutionScope;
use Fuzeo\Queue\Runtime\WordPressRuntime;

/**
 * Conservative site eligibility. Archived, spam, and deleted blogs are not executable.
 */
final class SitePolicy
{
    public function assertExecutable(WordPressRuntime $wp, ExecutionContext $context): void
    {
        if ($context->scope === ExecutionScope::Network) {
            return;
        }
        $status = $wp->siteStatus($context->siteId);
        if ($status === null) {
            throw new SiteUnavailableException(
                'Site ' . $context->siteId . ' no longer exists. The job will not run against another blog.'
            );
        }
        if (!empty($status['deleted'])) {
            throw new SiteUnavailableException('Site ' . $context->siteId . ' is deleted and is not an executable context.');
        }
        if (!empty($status['archived'])) {
            throw new SiteUnavailableException('Site ' . $context->siteId . ' is archived and is not an executable context.');
        }
        if (!empty($status['spam'])) {
            throw new SiteUnavailableException('Site ' . $context->siteId . ' is marked spam and is not an executable context.');
        }
    }
}
