<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Jobs;

use Fuzeo\Queue\Exceptions\QueueException;

/**
 * Where a job must run. Site-scoped work uses a concrete site ID.
 * Network-scoped work uses siteId = 0 and must not assume the current blog.
 */
final class ExecutionContext
{
    public function __construct(
        public readonly int $networkId,
        public readonly int $siteId,
        public readonly ExecutionScope $scope = ExecutionScope::Site,
    ) {
        if ($this->networkId < 1) {
            throw new QueueException('network_id must be a positive integer.');
        }

        if ($this->scope === ExecutionScope::Site && $this->siteId < 1) {
            throw new QueueException('site_id must be a positive integer for site-scoped jobs.');
        }

        if ($this->scope === ExecutionScope::Network && $this->siteId !== 0) {
            throw new QueueException('network-scoped jobs must use site_id 0.');
        }
    }

    public static function site(int $networkId, int $siteId): self
    {
        return new self($networkId, $siteId, ExecutionScope::Site);
    }

    public static function network(int $networkId): self
    {
        return new self($networkId, 0, ExecutionScope::Network);
    }

    public static function singleSite(?int $siteId = 1): self
    {
        return self::site(1, $siteId ?? 1);
    }

    /**
     * @return array{network_id: int, site_id: int, scope: string}
     */
    public function toArray(): array
    {
        return [
            'network_id' => $this->networkId,
            'site_id' => $this->siteId,
            'scope' => $this->scope->value,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $networkId = $data['network_id'] ?? null;
        $siteId = $data['site_id'] ?? null;
        $scope = $data['scope'] ?? ExecutionScope::Site->value;

        if (!is_int($networkId) || !is_int($siteId) || !is_string($scope)) {
            throw new QueueException('Execution context requires integer network_id, integer site_id, and string scope.');
        }

        $enum = ExecutionScope::tryFrom($scope);
        if ($enum === null) {
            throw new QueueException('Unknown execution scope "' . $scope . '".');
        }

        return new self($networkId, $siteId, $enum);
    }
}
