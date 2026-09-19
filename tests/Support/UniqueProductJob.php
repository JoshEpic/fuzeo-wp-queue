<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Support;

use Fuzeo\Queue\Jobs\Job;
use Fuzeo\Queue\Jobs\UniqueJob;

final class UniqueProductJob implements Job, UniqueJob
{
    public function __construct(private readonly int $productId)
    {
    }

    public static function type(): string
    {
        return 'acme.sync_product';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }

    public function payload(): array
    {
        return ['product_id' => $this->productId];
    }

    public function uniqueKey(): string
    {
        return 'product:' . $this->productId;
    }
}
