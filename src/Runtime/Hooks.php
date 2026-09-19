<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Runtime;

final class Hooks
{
    public const READY = 'fuzeo_queue_ready';
    public const BEFORE_JOB = 'fuzeo_queue_before_job';
    public const AFTER_JOB = 'fuzeo_queue_after_job';
    public const JOB_FAILED = 'fuzeo_queue_job_failed';
    public const WORKER_STOPPING = 'fuzeo_queue_worker_stopping';

    public static function emit(string $hook, mixed ...$arguments): void
    {
        if (function_exists('do_action')) {
            do_action($hook, ...$arguments);
        }
    }
}
