<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Runtime;

final class Hooks
{
    public const READY = 'fuzeo_queue_ready';
    public const WORKER_STARTED = 'fuzeo_queue_worker_started';
    public const JOB_PREPARING = 'fuzeo_queue_job_preparing';
    public const JOB_STARTING = 'fuzeo_queue_job_starting';
    public const BEFORE_JOB = 'fuzeo_queue_before_job';
    public const AFTER_JOB = 'fuzeo_queue_after_job';
    public const JOB_COMPLETED = 'fuzeo_queue_job_completed';
    public const JOB_FAILED = 'fuzeo_queue_job_failed';
    public const JOB_CANCELLED = 'fuzeo_queue_job_cancelled';
    public const JOB_FINISHED = 'fuzeo_queue_job_finished';
    public const RUNTIME_RESET = 'fuzeo_queue_runtime_reset';
    public const WORKER_STOPPING = 'fuzeo_queue_worker_stopping';
    public const WORKER_STOPPED = 'fuzeo_queue_worker_stopped';

    public static function emit(string $hook, mixed ...$arguments): void
    {
        if (function_exists('do_action')) {
            do_action($hook, ...$arguments);
        }
    }
}
