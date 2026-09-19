<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Drivers;

final class Failure
{
    public function __construct(
        public readonly string $message,
        public readonly ?string $class = null,
        public readonly ?string $trace = null,
    ) {
    }
}
