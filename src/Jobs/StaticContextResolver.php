<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Jobs;

use Fuzeo\Queue\Contracts\ExecutionContextResolver;

final class StaticContextResolver implements ExecutionContextResolver
{
    public function __construct(private ExecutionContext $context)
    {
    }

    public function current(): ExecutionContext
    {
        return $this->context;
    }
}
