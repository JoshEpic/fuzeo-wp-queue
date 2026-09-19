<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Operations;

enum HealthStatus: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Critical = 'critical';
    case Unknown = 'unknown';
}
