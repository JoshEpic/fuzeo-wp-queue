<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Deployment;

enum ReadinessReason: string
{
    case Ready = 'ready';
    case Draining = 'draining';
    case SchemaMismatch = 'schema_mismatch';
    case MigrationInProgress = 'migration_in_progress';
    case RuntimeStale = 'runtime_stale';
    case DriverUnavailable = 'driver_unavailable';
    case RestartRequested = 'restart_requested';
    case RuntimeIncompatible = 'runtime_incompatible';
    case Maintenance = 'maintenance';
}
