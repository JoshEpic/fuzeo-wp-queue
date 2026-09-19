<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Runtime;

enum RecycleReason: string
{
    case None = 'none';
    case Signal = 'signal';
    case MaxJobs = 'max_jobs';
    case MaxRuntime = 'max_runtime';
    case Memory = 'memory_threshold';
    case GenerationChanged = 'runtime_generation_changed';
    case HealthFailure = 'health_failure';
    case ContextCorruption = 'context_corruption';
    case TransactionLeak = 'transaction_rolled_back';
    case Manual = 'manual';
    case CyclesComplete = 'cycles_complete';
}
