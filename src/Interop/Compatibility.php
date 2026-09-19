<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

enum Compatibility: string
{
    case DeclaredCompatible = 'declared-compatible';
    case Unknown = 'unknown';
    case Incompatible = 'incompatible';
    case AlreadyMigrated = 'already-migrated';
    case NotEligible = 'not-eligible';
}
