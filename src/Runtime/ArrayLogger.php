<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Runtime;

final class ArrayLogger implements RuntimeLogger
{
    /** @var list<array{event: string, context: array<string, scalar|null>}> */
    public array $entries = [];

    /**
     * @param array<string, scalar|null> $context
     */
    public function log(string $event, array $context = []): void
    {
        $this->entries[] = ['event' => $event, 'context' => $context];
    }

    public function has(string $event): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry['event'] === $event) {
                return true;
            }
        }

        return false;
    }
}
