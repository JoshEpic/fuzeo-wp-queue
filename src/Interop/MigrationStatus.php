<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

enum MigrationStatus: string
{
    case Planned = 'planned';
    case Completed = 'completed';
    case RollbackAvailable = 'rollback_available';
    case RolledBack = 'rolled_back';
    case Failed = 'failed';
    case Conflicted = 'conflicted';
}
