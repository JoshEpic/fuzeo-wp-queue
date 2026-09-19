<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Contracts;

use Fuzeo\Queue\Jobs\ExecutionContext;

interface ExecutionContextResolver
{
    public function current(): ExecutionContext;
}
