<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Worker;

use Fuzeo\Queue\Jobs\ExecutionContext;

final class NullSiteSwitcher implements SiteSwitcher
{
    public function run(ExecutionContext $context, callable $callback): mixed
    {
        unset($context);

        return $callback();
    }
}
