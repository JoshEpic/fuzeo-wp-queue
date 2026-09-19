<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Worker;

enum WorkerStatus: string
{
    case Starting = 'starting';
    case Idle = 'idle';
    case Working = 'working';
    case Running = 'running';
    case Stopping = 'stopping';
    case Stopped = 'stopped';
    case Unhealthy = 'unhealthy';
    case Draining = 'draining';
}
