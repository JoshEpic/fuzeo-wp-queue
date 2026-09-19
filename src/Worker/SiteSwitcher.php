<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Worker;

use Fuzeo\Queue\Jobs\ExecutionContext;

interface SiteSwitcher
{
    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function run(ExecutionContext $context, callable $callback): mixed;
}
