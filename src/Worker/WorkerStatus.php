<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Worker;

enum WorkerStatus: string
{
    case Running = 'running';
    case Stopping = 'stopping';
    case Stopped = 'stopped';
}
