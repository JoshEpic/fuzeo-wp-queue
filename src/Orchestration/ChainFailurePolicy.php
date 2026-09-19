<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Orchestration;

enum ChainFailurePolicy: string
{
    case Stop = 'stop';
}
