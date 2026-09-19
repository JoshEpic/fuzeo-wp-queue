<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Runtime;

final class Diagnostic
{
    public function __construct(
        public readonly string $severity,
        public readonly string $code,
        public readonly string $message,
    ) {
    }
}
