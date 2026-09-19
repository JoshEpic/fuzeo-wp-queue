<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Execution;

enum ExecutionClass: string
{
    case Standard = 'standard';
    case Persistent = 'persistent';
}
