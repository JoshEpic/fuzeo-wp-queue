<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Deployment;

use Fuzeo\Queue\Runtime\RecycleReason;

/**
 * Stable process-manager exit codes. Keep this list small.
 *
 * 0 = clean recycle/drain/signal
 * 1 = startup/config/health failure
 * 2 = schema incompatibility
 * 3 = backend unavailable
 * 4 = runtime incompatibility
 */
final class ProcessExitCode
{
    public const OK = 0;

    public const STARTUP = 1;

    public const SCHEMA = 2;

    public const BACKEND = 3;

    public const RUNTIME = 4;

    public static function fromReason(RecycleReason $reason): int
    {
        return match ($reason) {
            RecycleReason::SchemaMismatch => self::SCHEMA,
            RecycleReason::RuntimeIncompatible => self::RUNTIME,
            RecycleReason::HealthFailure => self::BACKEND,
            RecycleReason::None => self::OK,
            default => self::OK,
        };
    }

    public static function fromReadinessReason(string $reason): int
    {
        return match ($reason) {
            ReadinessReason::Ready->value => self::OK,
            ReadinessReason::SchemaMismatch->value, ReadinessReason::MigrationInProgress->value => self::SCHEMA,
            ReadinessReason::DriverUnavailable->value => self::BACKEND,
            ReadinessReason::RuntimeIncompatible->value, ReadinessReason::RuntimeStale->value => self::RUNTIME,
            default => self::STARTUP,
        };
    }
}
