<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Runtime;

final class ResetResult
{
    /**
     * @param list<string> $actions
     */
    public function __construct(
        public readonly bool $ok,
        public readonly array $actions = [],
        public readonly ?RecycleReason $recycle = null,
        public readonly string $message = '',
    ) {
    }

    public static function ok(string ...$actions): self
    {
        return new self(true, array_values($actions));
    }

    public static function recycle(RecycleReason $reason, string $message, string ...$actions): self
    {
        return new self(false, array_values($actions), $reason, $message);
    }
}
