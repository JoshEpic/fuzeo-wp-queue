<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Drivers;

use Fuzeo\Queue\Idempotency\IdempotencyStore;

interface ProvidesIdempotencyStore
{
    public function idempotencyStore(): IdempotencyStore;
}
