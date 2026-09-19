<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Drivers;

final class DriverHealth
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $driver,
        public readonly array $details = [],
        public readonly ?string $message = null,
    ) {
    }
}
