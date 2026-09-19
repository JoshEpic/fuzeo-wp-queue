<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Drivers;

use Fuzeo\Queue\Orchestration\OrchestrationStore;

interface ProvidesOrchestration
{
    public function orchestration(): OrchestrationStore;
}
