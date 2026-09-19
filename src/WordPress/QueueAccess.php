<?php

declare(strict_types=1);

namespace Fuzeo\Queue\WordPress;

use Fuzeo\Queue\Jobs\ExecutionContext;
use Fuzeo\Queue\Jobs\ExecutionScope;

/**
 * Authorization boundary for future REST/dashboard. CLI operators do not use this.
 */
final class QueueAccess
{
    public function canViewSite(int $siteId): bool
    {
        if ($this->canManageNetwork()) {
            return true;
        }
        if (!$this->userCan(Capabilities::VIEW) && !$this->userCan(Capabilities::FALLBACK_SITE)) {
            return false;
        }

        return !$this->isMultisite() || $this->currentBlogId() === $siteId;
    }

    public function canManageSite(int $siteId): bool
    {
        if ($this->canManageNetwork()) {
            return true;
        }
        if (!$this->userCan(Capabilities::MANAGE) && !$this->userCan(Capabilities::FALLBACK_SITE)) {
            return false;
        }

        return !$this->isMultisite() || $this->currentBlogId() === $siteId;
    }

    public function canRetrySite(int $siteId): bool
    {
        if ($this->canManageNetwork()) {
            return true;
        }
        if (!$this->userCan(Capabilities::RETRY) && !$this->userCan(Capabilities::MANAGE) && !$this->userCan(Capabilities::FALLBACK_SITE)) {
            return false;
        }

        return $this->canViewSite($siteId);
    }

    public function canViewNetwork(): bool
    {
        return $this->userCan(Capabilities::NETWORK_VIEW)
            || $this->userCan(Capabilities::NETWORK_MANAGE)
            || $this->userCan(Capabilities::FALLBACK_NETWORK);
    }

    public function canManageNetwork(): bool
    {
        return $this->userCan(Capabilities::NETWORK_MANAGE)
            || $this->userCan(Capabilities::FALLBACK_NETWORK);
    }

    public function canAccess(ExecutionContext $context): bool
    {
        if ($context->scope === ExecutionScope::Network) {
            return $this->canViewNetwork();
        }

        return $this->canViewSite($context->siteId);
    }

    private function userCan(string $capability): bool
    {
        return function_exists('current_user_can') && current_user_can($capability);
    }

    private function isMultisite(): bool
    {
        return function_exists('is_multisite') && is_multisite();
    }

    private function currentBlogId(): int
    {
        return function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1;
    }
}
