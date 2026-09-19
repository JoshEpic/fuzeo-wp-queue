<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Execution;

interface CompatStore
{
    public function load(): CompatState;

    public function save(CompatState $state): void;
}
