<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Metrics;

/**
 * Stable metric vocabulary. Not a public custom-metrics API.
 */
final class MetricName
{
    public const JOBS_DISPATCHED = 'jobs_dispatched';
    public const JOBS_COMPLETED = 'jobs_completed';
    public const JOBS_FAILED = 'jobs_failed';
    public const JOBS_RETRIED = 'jobs_retried';
    public const RETRY_ATTEMPTS = 'retry_attempts';
    public const RETRY_SUCCESS = 'retry_success';
    public const RETRY_EXHAUSTED = 'retry_exhausted';
    public const JOBS_DEAD = 'jobs_dead';
    public const JOBS_CANCELLED = 'jobs_cancelled';
    public const WAIT_MS = 'queue_wait_ms';
    public const RUNTIME_MS = 'runtime_ms';
    public const SUCCESS_MS = 'time_to_success_ms';
    public const RETRY_DELAY_MS = 'retry_delay_ms';
    public const SCHEDULE_DISPATCHED = 'schedule_dispatched';
    public const SCHEDULE_MISSED = 'schedule_missed';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::JOBS_DISPATCHED,
            self::JOBS_COMPLETED,
            self::JOBS_FAILED,
            self::JOBS_RETRIED,
            self::RETRY_ATTEMPTS,
            self::RETRY_SUCCESS,
            self::RETRY_EXHAUSTED,
            self::JOBS_DEAD,
            self::JOBS_CANCELLED,
            self::WAIT_MS,
            self::RUNTIME_MS,
            self::SUCCESS_MS,
            self::RETRY_DELAY_MS,
            self::SCHEDULE_DISPATCHED,
            self::SCHEDULE_MISSED,
        ];
    }

    public static function isKnown(string $name): bool
    {
        return in_array($name, self::all(), true) || str_starts_with($name, self::WAIT_MS . '_le_')
            || str_starts_with($name, self::RUNTIME_MS . '_le_')
            || str_starts_with($name, self::SUCCESS_MS . '_le_')
            || str_starts_with($name, self::RETRY_DELAY_MS . '_le_');
    }
}
