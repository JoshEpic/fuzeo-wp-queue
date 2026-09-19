<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Execution;

use Fuzeo\Queue\Config\Config;
use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Core\QueueManager;
use Fuzeo\Queue\Drivers\ProvidesWorkerStore;
use Fuzeo\Queue\Drivers\Redis\RedisDriver;
use Fuzeo\Queue\Jobs\QueueName;
use Fuzeo\Queue\Locks\DistributedLock;
use Fuzeo\Queue\Support\SystemClock;
use Fuzeo\Queue\Support\Ulid;
use Fuzeo\Queue\Worker\WorkerLoop;
use Fuzeo\Queue\Worker\WorkerOptions;
use Fuzeo\Queue\Worker\WorkerStatus;

final class ExecutionRuntime
{
    public const RUNNER_LOCK = 'fuzeo-compat-tick';

    public function __construct(
        private readonly QueueManager $manager,
        private readonly CompatStore $store,
        private readonly DistributedLock $lock,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public static function fromManager(QueueManager $manager): self
    {
        $driver = $manager->driver();
        $lock = new MemoryRunnerLock($manager->clock());
        if ($driver instanceof RedisDriver) {
            $lock = $driver->lock();
        } elseif ($driver instanceof \Fuzeo\Queue\Drivers\MySql\MySqlDriver) {
            $lock = new MysqlRunnerLock($driver->connection(), $manager->clock());
        }
        $store = defined('ABSPATH') && (function_exists('get_option') || function_exists('get_network_option'))
            ? new WordPressCompatStore()
            : new MemoryCompatStore();

        return new self($manager, $store, $lock, $manager->clock());
    }

    public function store(): CompatStore
    {
        return $this->store;
    }

    public function lock(): DistributedLock
    {
        return $this->lock;
    }

    public function fleet(): FleetObserver
    {
        return new FleetObserver($this->manager->driver(), $this->manager->config(), $this->clock);
    }

    public function mode(): ExecutionMode
    {
        $config = $this->manager->config();
        if ($config->executionModeOverride !== '') {
            $forced = ExecutionMode::tryFrom($config->executionModeOverride);
            if ($forced !== null) {
                return $forced;
            }
        }
        $fleet = $this->fleet();
        if ($fleet->hasHealthyPersistentWorkers()) {
            return ExecutionMode::Persistent;
        }
        $state = $this->state();
        $cronAt = $state->lastCronWorkerAt ?? $fleet->lastCronCliAt();
        if ($cronAt !== null && ($this->clock->now()->getTimestamp() - $cronAt->getTimestamp()) <= 120) {
            return ExecutionMode::CronCli;
        }
        if (
            $this->isEnabled()
            && $state->lastTickAt !== null
            && ($this->clock->now()->getTimestamp() - $state->lastTickAt->getTimestamp()) <= 300
        ) {
            return ExecutionMode::WordPressCompat;
        }
        if ($this->isEnabled()) {
            return ExecutionMode::WordPressCompat;
        }

        return ExecutionMode::None;
    }

    public function isEnabled(): bool
    {
        $state = $this->state();
        if ($state->enabledExplicit) {
            return $state->enabled;
        }

        return $this->manager->config()->compatibilityEnabled;
    }

    public function enable(): CompatState
    {
        $current = $this->state();
        $next = new CompatState(
            enabled: true,
            lastTickAt: $current->lastTickAt,
            lastSuccessAt: $current->lastSuccessAt,
            lastJobsProcessed: $current->lastJobsProcessed,
            lastOutcome: $current->lastOutcome,
            lastCronWorkerAt: $current->lastCronWorkerAt,
            lastBlockedReason: $current->lastBlockedReason,
            lastBlockedJobType: $current->lastBlockedJobType,
            lastBlockedQueue: $current->lastBlockedQueue,
            wpCronConfigured: $current->wpCronConfigured,
            enabledExplicit: true,
        );
        $this->store->save($next);
        CompatTrigger::reconcile($this->manager, $next);

        return $next;
    }

    public function disable(): CompatState
    {
        $current = $this->state();
        $next = new CompatState(
            enabled: false,
            lastTickAt: $current->lastTickAt,
            lastSuccessAt: $current->lastSuccessAt,
            lastJobsProcessed: $current->lastJobsProcessed,
            lastOutcome: $current->lastOutcome,
            lastCronWorkerAt: $current->lastCronWorkerAt,
            lastBlockedReason: $current->lastBlockedReason,
            lastBlockedJobType: $current->lastBlockedJobType,
            lastBlockedQueue: $current->lastBlockedQueue,
            wpCronConfigured: false,
            enabledExplicit: true,
        );
        $this->store->save($next);
        CompatTrigger::unschedule();

        return $next;
    }

    public function state(): CompatState
    {
        return $this->store->load();
    }

    public function recordCronWorker(): void
    {
        $current = $this->state();
        $this->store->save(new CompatState(
            enabled: $current->enabled,
            lastTickAt: $current->lastTickAt,
            lastSuccessAt: $current->lastSuccessAt,
            lastJobsProcessed: $current->lastJobsProcessed,
            lastOutcome: $current->lastOutcome,
            lastCronWorkerAt: $this->clock->now(),
            lastBlockedReason: $current->lastBlockedReason,
            lastBlockedJobType: $current->lastBlockedJobType,
            lastBlockedQueue: $current->lastBlockedQueue,
            wpCronConfigured: $current->wpCronConfigured,
            enabledExplicit: $current->enabledExplicit,
        ));
    }

    /**
     * @return list<string>
     */
    public function allowedQueues(): array
    {
        $config = $this->manager->config();
        if ($config->compatibilityAllowedQueues !== []) {
            return array_values(array_map([QueueName::class, 'normalize'], $config->compatibilityAllowedQueues));
        }

        return [QueueName::normalize($config->defaultQueue)];
    }

    public function runtimeBudgetSeconds(): int
    {
        $configured = min(25, max(5, $this->manager->config()->compatibilityMaxRuntime));
        $php = (int) ini_get('max_execution_time');
        if ($php > 0) {
            $margin = max(5, $php - 10);

            return min($configured, $margin);
        }

        return $configured;
    }

    public function maxJobs(): int
    {
        return min(25, max(1, $this->manager->config()->compatibilityMaxJobs));
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $mode = $this->mode();
        $state = $this->state();
        $wpCronDisabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;

        return [
            'mode' => $mode->value,
            'mode_label' => $mode->label(),
            'capabilities' => ExecutionCapabilities::for($mode)->toArray(),
            'compatibility_enabled' => $this->isEnabled(),
            'wp_cron_automatic_spawning_disabled' => $wpCronDisabled,
            'wp_cron_configured' => $state->wpCronConfigured,
            'trigger_warning' => $this->isEnabled() && $wpCronDisabled && $mode !== ExecutionMode::CronCli && $mode !== ExecutionMode::Persistent
                ? 'configured but not being triggered'
                : '',
            'allowed_queues' => $this->allowedQueues(),
            'max_runtime' => $this->runtimeBudgetSeconds(),
            'max_jobs' => $this->maxJobs(),
            'stale_worker_grace' => $this->manager->config()->compatibilityStaleWorkerGrace,
            'persistent_workers_healthy' => $this->fleet()->hasHealthyPersistentWorkers(),
            'compat_may_process' => $this->fleet()->compatMayProcess(),
            'workers' => $this->classifiedWorkers(),
            'state' => $state->toArray(),
            'limitations' => $this->limitations($mode),
            'recommended_upgrade' => $this->upgradePath($mode),
        ];
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function classifiedWorkers(): array
    {
        $byType = [
            ProcessType::Persistent->value => [],
            ProcessType::CronCli->value => [],
            ProcessType::WordPressCompat->value => [],
        ];
        foreach ($this->fleet()->workersByType() as $row) {
            $type = $row['process_type'];
            if (!isset($byType[$type])) {
                $byType[$type] = [];
            }
            $byType[$type][] = $row;
        }

        return [
            'persistent' => $byType[ProcessType::Persistent->value],
            'cron_cli' => $byType[ProcessType::CronCli->value],
            'wordpress_compat' => $byType[ProcessType::WordPressCompat->value],
        ];
    }

    public function tick(bool $force = false, bool $manual = false): TickResult
    {
        $ops = $this->manager->operations();
        $verdict = $ops->compatibility();
        if (!$verdict->canReserve) {
            return $this->finishTick('skipped', 'deployment_or_schema_blocks_reserve');
        }
        $now = $this->clock->now();
        $deploy = $this->manager->deployment()->snapshot();
        if ($deploy->isMaintenanceActive($now) || $deploy->isDraining()) {
            return $this->finishTick('skipped', 'maintenance_or_drain');
        }
        if (!$force && !$this->isEnabled()) {
            return $this->finishTick('skipped', 'compatibility_disabled');
        }
        if (!$force && !$this->fleet()->compatMayProcess()) {
            try {
                $ops->recordEvent('compat.skipped_persistent_workers', 'execution', 'compat');
            } catch (\Throwable) {
            }

            return $this->finishTick('skipped', 'persistent_workers_healthy');
        }
        $token = Ulid::generate();
        $ttl = $this->runtimeBudgetSeconds() + 10;
        if (!$this->lock->acquire(self::RUNNER_LOCK, $token, $ttl)) {
            return $this->finishTick('skipped', 'runner_lock_held');
        }
        $schedules = 0;
        $processed = 0;
        $reconciled = 0;
        try {
            $schedules = $this->manager->scheduler()->runDue();
            $options = new WorkerOptions(
                queues: $this->allowedQueues(),
                sleepSeconds: 0,
                timeoutSeconds: min($this->runtimeBudgetSeconds(), $this->manager->config()->workerTimeoutSeconds),
                leaseSeconds: $this->manager->config()->leaseSeconds,
                memoryBytes: $this->manager->config()->workerMemoryBytes,
                maxJobs: $this->maxJobs(),
                maxRuntimeSeconds: $this->runtimeBudgetSeconds(),
                heartbeatIntervalSeconds: $this->manager->config()->heartbeatIntervalSeconds,
                staleWorkerSeconds: $this->manager->config()->staleWorkerSeconds,
                generationCheckInterval: $this->manager->config()->generationCheckInterval,
                gcInterval: $this->manager->config()->gcInterval,
                runtimeReset: $this->manager->config()->runtimeReset,
                recycleOnContextError: $this->manager->config()->workerRecycleOnContextError,
                processType: ProcessType::WordPressCompat,
                executionClass: ExecutionClass::Standard->value,
                maxTimeoutSeconds: $this->manager->config()->compatibilityMaxJobTimeout,
            );
            $worker = WorkerLoop::fromManager($this->manager, $options);
            $worker->run();
            $processed = $worker->processed();
            $reconciled = $this->manager->orchestrator()->reconcile(10);
            $this->pruneShortLivedWorkers();
            $this->recordMetrics($processed, true);
            try {
                $ops->recordEvent('compat.tick', 'execution', 'compat', [
                    'jobs' => $processed,
                    'schedules' => $schedules,
                    'manual' => $manual,
                ]);
            } catch (\Throwable) {
            }

            return $this->finishTick('ok', '', $processed, $schedules, $reconciled, true);
        } catch (\Throwable $exception) {
            $this->recordMetrics($processed, false);
            try {
                $ops->recordEvent('compat.tick', 'execution', 'compat', ['failed' => true]);
            } catch (\Throwable) {
            }
            unset($exception);

            return $this->finishTick('failed', 'tick_exception', $processed, $schedules, $reconciled);
        } finally {
            $this->lock->release(self::RUNNER_LOCK, $token);
        }
    }

    public function cronExample(): string
    {
        return implode("\n", [
            '# Replace paths with this WordPress install and the WP-CLI binary available on the host.',
            '* * * * * cd /path/to/wordpress && wp fuzeo-queue schedule-run',
            '* * * * * cd /path/to/wordpress && wp fuzeo-queue work --once --max-jobs=25 --max-runtime=50 --sleep=0',
            '# Combined alternative:',
            '* * * * * cd /path/to/wordpress && wp fuzeo-queue tick',
        ]);
    }

    /**
     * @return list<string>
     */
    private function limitations(ExecutionMode $mode): array
    {
        return match ($mode) {
            ExecutionMode::Persistent => [],
            ExecutionMode::CronCli => [
                'Throughput and latency depend on the cron interval.',
                'Overlapping invocations are safe (atomic reservation) but a runner lock skips extra processes.',
                'Schedule precision is the cron granularity, not a persistent scheduler loop.',
            ],
            ExecutionMode::WordPressCompat => [
                'Jobs are processed in bounded WordPress executions because no persistent worker is active.',
                'Throughput, latency, scheduling precision, and long-running job support are reduced.',
                'Jobs that require a persistent worker stay pending and are not failed.',
                'This is not full worker mode.',
            ],
            ExecutionMode::None => [
                'Queued jobs have no executor.',
            ],
        };
    }

    private function upgradePath(ExecutionMode $mode): string
    {
        return match ($mode) {
            ExecutionMode::Persistent => 'Persistent CLI workers are active.',
            ExecutionMode::CronCli => 'External cron is processing work. Persistent CLI workers remain the recommended architecture.',
            ExecutionMode::WordPressCompat => 'Configure persistent workers, or scheduled WP-CLI (`wp fuzeo-queue work --once`) if the host allows it.',
            ExecutionMode::None => 'Recommended: persistent `wp fuzeo-queue work`. Alternative: external cron. Compatibility: enable the bounded WordPress executor for eligible jobs.',
        };
    }

    private function finishTick(
        string $outcome,
        string $reason,
        int $jobs = 0,
        int $schedules = 0,
        int $reconciled = 0,
        bool $success = false,
    ): TickResult {
        $current = $this->state();
        $now = $this->clock->now();
        $this->store->save(new CompatState(
            enabled: $current->enabled,
            lastTickAt: $now,
            lastSuccessAt: $success ? $now : $current->lastSuccessAt,
            lastJobsProcessed: $jobs,
            lastOutcome: $outcome . ($reason !== '' ? ':' . $reason : ''),
            lastCronWorkerAt: $current->lastCronWorkerAt,
            lastBlockedReason: $current->lastBlockedReason,
            lastBlockedJobType: $current->lastBlockedJobType,
            lastBlockedQueue: $current->lastBlockedQueue,
            wpCronConfigured: $current->wpCronConfigured,
            enabledExplicit: $current->enabledExplicit,
        ));

        return new TickResult($outcome, $jobs, $schedules, $reconciled, $reason);
    }

    private function recordMetrics(int $processed, bool $ok): void
    {
        try {
            $metrics = $this->manager->metrics();
            $metrics->increment(\Fuzeo\Queue\Metrics\MetricName::COMPAT_TICKS, 1);
            if ($processed > 0) {
                $metrics->increment(\Fuzeo\Queue\Metrics\MetricName::COMPAT_JOBS_PROCESSED, $processed);
            }
            if (!$ok) {
                $metrics->increment(\Fuzeo\Queue\Metrics\MetricName::COMPAT_TICKS_FAILED, 1);
            }
        } catch (\Throwable) {
        }
    }

    private function pruneShortLivedWorkers(): void
    {
        $driver = $this->manager->driver();
        if (!$driver instanceof ProvidesWorkerStore) {
            return;
        }
        $store = $driver->workerStore();
        $cutoff = $this->clock->now()->getTimestamp() - 600;
        foreach ($store->all() as $row) {
            $type = (string) ($row['process_type'] ?? '');
            if (!in_array($type, [ProcessType::CronCli->value, ProcessType::WordPressCompat->value], true)) {
                continue;
            }
            $status = (string) ($row['status'] ?? '');
            $at = (string) ($row['last_heartbeat_at'] ?? '');
            $ts = 0;
            if ($at !== '') {
                try {
                    $ts = (str_contains($at, 'T') ? \Fuzeo\Queue\Support\Dates::fromAtom($at) : new \DateTimeImmutable($at, new \DateTimeZone('UTC')))->getTimestamp();
                } catch (\Throwable) {
                    $ts = 0;
                }
            }
            if ($status === WorkerStatus::Stopped->value && $ts < $cutoff) {
                $id = (string) ($row['worker_id'] ?? '');
                if ($id !== '') {
                    $store->stop($id);
                }
            }
        }
    }
}
