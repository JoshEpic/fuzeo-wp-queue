<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

use Fuzeo\Queue\Jobs\Job;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Jobs\QueueName;
use Fuzeo\Queue\Runtime\Coordinator;

/**
 * Developer-facing interoperability facade (1.1). Does not intercept WP-Cron or Action Scheduler.
 */
final class Interop
{
    public static function manager(): InteropManager
    {
        return Coordinator::get()->interop();
    }

    /**
     * @param callable(array<string, mixed>): Job $mapper
     */
    public static function actionScheduler(
        Origin $origin,
        string $hook,
        string $jobClass,
        callable $mapper,
        int $version = 1,
        string $group = '',
        string $queue = QueueName::DEFAULT,
        ?string $scheduleName = null,
        ?int $intervalSeconds = null,
        bool $migratePending = false,
    ): ActionSchedulerDescriptor {
        $descriptor = new ActionSchedulerDescriptor(
            $origin,
            $hook,
            $jobClass,
            $mapper,
            $version,
            $group,
            $queue,
            $scheduleName,
            $intervalSeconds,
            $migratePending,
        );
        self::manager()->registerActionScheduler($descriptor);

        return $descriptor;
    }

    /**
     * @param callable(list<mixed>): Job $mapper
     */
    public static function cron(
        Origin $origin,
        string $hook,
        string $jobClass,
        callable $mapper,
        int $version = 1,
        string|false|null $recurrence = null,
        ?int $intervalSeconds = null,
        string $queue = QueueName::DEFAULT,
        string $timezone = 'UTC',
        ?string $scheduleName = null,
        ?string $cronExpression = null,
        ?string $dailyAt = null,
    ): CronDescriptor {
        $descriptor = new CronDescriptor(
            $origin,
            $hook,
            $jobClass,
            $mapper,
            $version,
            $recurrence,
            $intervalSeconds,
            $queue,
            $timezone,
            $scheduleName,
            $cronExpression,
            $dailyAt,
        );
        self::manager()->registerCron($descriptor);

        return $descriptor;
    }

    public static function runtime(Origin $origin, RuntimePolicy $policy = RuntimePolicy::PreferQueue): AsyncRuntime
    {
        if (!Coordinator::isBooted()) {
            return (new RuntimeResolver(null, new NativeActionSchedulerGateway(), $origin))->resolve($policy);
        }

        return self::manager()->runtime($origin, $policy);
    }

    public static function fake(): FakeAsyncRuntime
    {
        return self::manager()->fake();
    }
}
