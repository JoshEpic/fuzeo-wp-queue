<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Runtime;

/**
 * Structured worker diagnostics. Implementations must never log secrets or raw payloads.
 */
interface RuntimeLogger
{
    /**
     * @param array<string, scalar|null> $context
     */
    public function log(string $event, array $context = []): void;
}
