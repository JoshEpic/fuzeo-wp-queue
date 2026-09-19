<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Concurrency;

use Fuzeo\Queue\Config\Config;
use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\RateLimit\RateLimit;

final class AdmissionPolicy
{
    /**
     * @param array<string, int> $concurrencyByQueue
     * @param list<RateLimit> $globalRateLimits
     */
    public function __construct(
        public readonly array $concurrencyByQueue = [],
        public readonly array $globalRateLimits = [],
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        $limits = [];
        foreach ($config->rateLimits as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $limits[] = RateLimit::fromArray($row);
            }
        }

        return new self($config->concurrency, $limits);
    }

    public function concurrencyFor(string $queue): ?int
    {
        return $this->concurrencyByQueue[$queue] ?? null;
    }

    public function rateLimitFor(Envelope $envelope): ?RateLimit
    {
        $fromJob = TokenBucket::fromEnvelope($envelope);
        if ($fromJob !== null) {
            return $fromJob;
        }
        foreach ($this->globalRateLimits as $limit) {
            if ($limit->key === $envelope->queue) {
                return $limit;
            }
        }

        return null;
    }

    public function isEmpty(): bool
    {
        return $this->concurrencyByQueue === [] && $this->globalRateLimits === [];
    }
}
