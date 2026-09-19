<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Support;

use Fuzeo\Queue\Jobs\Job;
use Fuzeo\Queue\Retry\FixedBackoff;
use Fuzeo\Queue\Retry\Retryable;
use Fuzeo\Queue\Retry\RetryPolicy;

final class RetrySoonJob implements Job, Retryable
{
    public function __construct(private readonly int $orderId)
    {
    }

    public static function type(): string
    {
        return 'acme.retry_soon';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }

    public function payload(): array
    {
        return ['order_id' => $this->orderId];
    }

    public function retryPolicy(): RetryPolicy
    {
        return new RetryPolicy(5, new FixedBackoff(0));
    }
}
