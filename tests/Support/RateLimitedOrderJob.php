<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Support;

use Fuzeo\Queue\Jobs\Job;
use Fuzeo\Queue\RateLimit\RateLimit;
use Fuzeo\Queue\RateLimit\RateLimited;

final class RateLimitedOrderJob implements Job, RateLimited
{
    public function __construct(private readonly int $orderId)
    {
    }

    public static function type(): string
    {
        return 'acme.rate_limited_order';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }

    public function payload(): array
    {
        return ['order_id' => $this->orderId];
    }

    public function rateLimit(): RateLimit
    {
        return RateLimit::perSecond(1, 'vendor-api');
    }
}
