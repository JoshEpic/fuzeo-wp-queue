<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Operations;

use Fuzeo\Queue\Jobs\ExecutionContext;
use Fuzeo\Queue\Jobs\ExecutionScope;
use Fuzeo\Queue\WordPress\QueueAccess;

final class Operator
{
    public function __construct(
        public readonly QueueAccess $access,
        public readonly int $userId = 0,
        public readonly int $currentSiteId = 1,
        public readonly bool $networkAdmin = false,
        public readonly bool $cli = false,
    ) {
    }

    public static function cli(): self
    {
        return new self(new QueueAccess(), 0, 1, true, true);
    }

    public function scopedSiteId(): ?int
    {
        if ($this->cli || $this->networkAdmin || $this->access->canViewNetwork()) {
            return null;
        }

        return $this->currentSiteId;
    }

    public function assertViewContext(ExecutionContext $context): void
    {
        if ($this->cli) {
            return;
        }
        if (!$this->access->canAccess($context)) {
            throw new AccessDenied('Not authorized to inspect this queue resource.');
        }
    }

    public function assertViewSite(int $siteId, string $scope = 'site'): void
    {
        if ($this->cli) {
            return;
        }
        if ($scope === ExecutionScope::Network->value) {
            if (!$this->access->canViewNetwork()) {
                throw new AccessDenied('Not authorized to inspect network queue resources.');
            }

            return;
        }
        if (!$this->access->canViewSite($siteId)) {
            throw new AccessDenied('Not authorized to inspect this site.');
        }
    }

    public function assertRetry(int $siteId): void
    {
        if ($this->cli) {
            return;
        }
        if (!$this->access->canRetrySite($siteId)) {
            throw new AccessDenied('Not authorized to retry jobs.');
        }
    }

    public function assertManage(int $siteId): void
    {
        if ($this->cli) {
            return;
        }
        if (!$this->access->canManageSite($siteId) && !$this->access->canManageNetwork()) {
            throw new AccessDenied('Not authorized to manage this queue resource.');
        }
    }

    public function assertFleetManage(): void
    {
        if ($this->cli) {
            return;
        }
        if ($this->access->isNetworkInstall() && !$this->access->canManageNetwork()) {
            throw new AccessDenied('Network manage permission is required to control the worker fleet.');
        }
        $this->assertManage($this->currentSiteId);
    }

    public function canPayload(int $siteId): bool
    {
        return $this->cli || $this->access->canViewPayload($siteId);
    }

    public function canTrace(int $siteId): bool
    {
        return $this->cli || $this->access->canViewTrace($siteId);
    }

    public function requestedSiteFilter(?int $requested): ?int
    {
        $forced = $this->scopedSiteId();
        if ($forced !== null) {
            return $forced;
        }
        if ($requested !== null && !$this->cli && !$this->access->canViewNetwork() && $requested !== $this->currentSiteId) {
            throw new AccessDenied('Not authorized to inspect another site.');
        }

        return $requested;
    }
}
