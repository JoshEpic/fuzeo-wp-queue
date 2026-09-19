<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Runtime;

final class NullLogger implements RuntimeLogger
{
    /**
     * @param array<string, scalar|null> $context
     */
    public function log(string $event, array $context = []): void
    {
        unset($event, $context);
    }
}
