<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Execution;

final class MemoryCompatStore implements CompatStore
{
    private CompatState $state;

    public function __construct(?CompatState $state = null)
    {
        $this->state = $state ?? new CompatState();
    }

    public function load(): CompatState
    {
        return $this->state;
    }

    public function save(CompatState $state): void
    {
        $this->state = $state;
    }
}
