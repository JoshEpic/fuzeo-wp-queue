<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Orchestration;

enum BatchFailurePolicy: string
{
    case CollectAll = 'collect-all';
    case FailFast = 'fail-fast';
}
