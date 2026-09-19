<?php

declare(strict_types=1);

namespace Fuzeo\Queue\WordPress;

use Fuzeo\Queue\Drivers\MySql\MySqlDriver;
use Fuzeo\Queue\Drivers\ProvidesWorkerStore;
use Fuzeo\Queue\Drivers\QueueDriver;
use Fuzeo\Queue\Drivers\StatusAware;
use Fuzeo\Queue\Jobs\JobState;
use Fuzeo\Queue\Persistence\SchemaOwner;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Runtime\PackageInfo;
use Fuzeo\Queue\Runtime\RuntimeGeneration;
use Fuzeo\Queue\Runtime\WordPressRuntime;
use Fuzeo\Queue\Worker\WorkerStatus;

/**
 * Diagnostic Site Health checks. Not an analytics product.
 */
final class SiteHealth
{
    public function __construct(
        private readonly WordPressRuntime $wp,
        private readonly RuntimeGeneration $generation,
        private readonly int $staleWorkerSeconds = 30,
        private readonly int $backlogCritical = 1000,
    ) {
    }

    /**
     * @return array<string, array{label: string, test: callable(): array<string, mixed>}>
     */
    public function tests(): array
    {
        return [
            'fuzeo_queue_driver' => [
                'label' => $this->translate('Fuzeo Queue driver'),
                'test' => [$this, 'testDriver'],
            ],
            'fuzeo_queue_schema' => [
                'label' => $this->translate('Fuzeo Queue schema'),
                'test' => [$this, 'testSchema'],
            ],
            'fuzeo_queue_workers' => [
                'label' => $this->translate('Fuzeo Queue workers'),
                'test' => [$this, 'testWorkers'],
            ],
            'fuzeo_queue_generation' => [
                'label' => $this->translate('Fuzeo Queue runtime generation'),
                'test' => [$this, 'testGeneration'],
            ],
            'fuzeo_queue_redis' => [
                'label' => $this->translate('Fuzeo Queue Redis policy'),
                'test' => [$this, 'testRedis'],
            ],
            'fuzeo_queue_execution' => [
                'label' => $this->translate('Fuzeo Queue execution mode'),
                'test' => [$this, 'testExecution'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function testDriver(): array
    {
        $health = $this->driver()?->health();
        if ($health === null) {
            return $this->result('critical', 'Fuzeo Queue is not booted.');
        }
        if (!$health->ok || $health->driver === 'unavailable') {
            return $this->result('critical', $health->message ?? 'The queue driver is unavailable.');
        }

        return $this->result('good', 'Queue driver "' . $health->driver . '" is available.');
    }

    /**
     * @return array<string, mixed>
     */
    public function testSchema(): array
    {
        $driver = $this->driver();
        if ($driver === null) {
            return $this->result('recommended', 'Queue runtime is not booted; schema cannot be verified.');
        }
        if ($driver instanceof MySqlDriver) {
            try {
                $driver->requireCurrentSchema();
            } catch (\Throwable $exception) {
                return $this->result('critical', $exception->getMessage());
            }
        }

        return $this->result('good', 'Queue schema version ' . SchemaOwner::CURRENT_VERSION . ' is current.');
    }

    /**
     * @return array<string, mixed>
     */
    public function testWorkers(): array
    {
        $driver = $this->driver();
        if ($driver === null) {
            return $this->result('recommended', 'Queue runtime is not booted.');
        }
        $pending = 0;
        if ($driver instanceof StatusAware) {
            $counts = $driver->countsByState();
            $pending = (int) ($counts[JobState::Pending->value] ?? 0);
        }
        $alive = 0;
        $stale = 0;
        if ($driver instanceof ProvidesWorkerStore) {
            foreach ($driver->workerStore()->all() as $row) {
                if ($driver->workerStore()->isStale($row, $this->staleWorkerSeconds)) {
                    $stale++;
                    continue;
                }
                $status = (string) ($row['status'] ?? '');
                $type = (string) ($row['process_type'] ?? 'persistent');
                if ($status !== WorkerStatus::Stopped->value && $type === 'persistent') {
                    $alive++;
                }
            }
        }
        if ($pending > 0 && $alive === 0) {
            if (Coordinator::isBooted()) {
                $snap = Coordinator::get()->deployment()->snapshot();
                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                if ($snap->isDraining() || $snap->isMaintenanceActive($now)) {
                    return $this->result(
                        'recommended',
                        'Jobs are waiting while Fuzeo Queue is draining or in deployment maintenance. This is an intentional operational state.'
                    );
                }
            }
            $status = $pending >= $this->backlogCritical ? 'critical' : 'recommended';

            return $this->result($status, 'Jobs are waiting, but no active Fuzeo Queue worker is detected. Recommended: persistent `wp fuzeo-queue work`. Alternative: `wp fuzeo-queue work --once` from external cron. Compatibility: enable the bounded WordPress executor.');
        }
        if ($stale > 0) {
            return $this->result('recommended', $stale . ' worker heartbeat(s) are stale.');
        }
        if ($pending === 0 && $alive === 0) {
            return $this->result('good', 'No pending jobs. Workers are not required until work is queued.');
        }

        return $this->result('good', $alive . ' live worker(s); pending jobs: ' . $pending . '.');
    }

    /**
     * @return array<string, mixed>
     */
    public function testGeneration(): array
    {
        $token = Coordinator::isBooted() ? Coordinator::get()->config()->deploymentId : '';
        $extra = $token !== '' ? ' Explicit deployment token is configured.' : '';

        return $this->result(
            'good',
            'Current runtime generation ' . substr($this->generation->current(), 0, 12)
            . '. Workers recycle when plugins, themes, the Queue package, or the deployment token change.'
            . $extra
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function testRedis(): array
    {
        $health = $this->driver()?->health();
        if ($health === null || $health->driver !== 'redis') {
            return $this->result('good', 'Redis is not the active queue driver.');
        }
        $policy = (string) ($health->details['maxmemory_policy'] ?? '');
        if ($policy === '') {
            return $this->result('recommended', 'Unable to read Redis maxmemory-policy.');
        }
        if (str_starts_with($policy, 'allkeys-')) {
            return $this->result(
                'critical',
                'Redis maxmemory-policy "' . $policy . '" can evict durable queue keys. Use noeviction or volatile-* on a dedicated instance.'
            );
        }
        if (is_string($health->message) && $health->message !== '') {
            return $this->result('recommended', $health->message);
        }

        return $this->result('good', 'Redis maxmemory-policy is "' . $policy . '".');
    }

    /**
     * @return array<string, mixed>
     */
    public function testExecution(): array
    {
        if (!Coordinator::isBooted()) {
            return $this->result('recommended', 'Queue runtime is not booted.');
        }
        $exec = Coordinator::get()->execution();
        $mode = $exec->mode();
        $snap = $exec->snapshot();
        if ($mode === \Fuzeo\Queue\Execution\ExecutionMode::Persistent) {
            return $this->result('good', 'Persistent workers are active.');
        }
        if ($mode === \Fuzeo\Queue\Execution\ExecutionMode::CronCli) {
            return $this->result('good', 'Bounded CLI execution is active via external cron. Persistent workers remain recommended for heavy workloads.');
        }
        if ($mode === \Fuzeo\Queue\Execution\ExecutionMode::WordPressCompat) {
            $warning = (string) ($snap['trigger_warning'] ?? '');
            if ($warning !== '') {
                return $this->result('critical', 'WordPress Compatibility Mode is configured but not being triggered. DISABLE_WP_CRON is set and no external cron heartbeat is known.');
            }

            return $this->result(
                'recommended',
                'WordPress Compatibility Mode is active. Jobs are processed in bounded WordPress executions. Persistent workers offer better performance.'
            );
        }
        $pending = 0;
        $driver = $this->driver();
        if ($driver instanceof StatusAware) {
            $pending = (int) ($driver->countsByState()[JobState::Pending->value] ?? 0);
        }
        if ($pending > 0) {
            return $this->result('critical', 'Queued jobs have no executor. Configure persistent workers, external cron, or enable WordPress Compatibility Mode.');
        }

        return $this->result('good', 'No pending jobs. Configure an executor before dispatching work.');
    }

    /**
     * @return array<string, mixed>
     */
    public function debug(): array
    {
        $driver = $this->driver();
        $health = $driver?->health();
        $workers = 0;
        $schedulers = 0;
        if ($driver instanceof ProvidesWorkerStore) {
            foreach ($driver->workerStore()->all() as $row) {
                if (($row['status'] ?? '') !== WorkerStatus::Stopped->value) {
                    $workers++;
                }
            }
        }
        if (Coordinator::isBooted()) {
            try {
                $schedulers = count(Coordinator::get()->schedules()->store()->schedulers());
            } catch (\Throwable) {
                $schedulers = 0;
            }
        }

        return [
            'package_version' => PackageInfo::VERSION,
            'loaded_class_version' => PackageInfo::VERSION,
            'compatibility_series' => PackageInfo::COMPATIBILITY_SERIES,
            'schema_version' => SchemaOwner::CURRENT_VERSION,
            'driver' => $health?->driver ?? 'none',
            'worker_count' => $workers,
            'scheduler_count' => $schedulers,
            'runtime_generation' => $this->generation->current(),
            'multisite' => $this->wp->isMultisite(),
            'object_cache_dropin' => $this->wp->objectCacheDropInPresent(),
            'redis_version' => (string) ($health?->details['redis_version'] ?? ''),
            'execution_mode' => Coordinator::isBooted() ? Coordinator::get()->execution()->mode()->value : 'none',
        ];
    }

    private function driver(): ?QueueDriver
    {
        if (!Coordinator::isBooted()) {
            return null;
        }

        return Coordinator::get()->driver();
    }

    /**
     * @return array<string, mixed>
     */
    private function result(string $status, string $description): array
    {
        $badge = match ($status) {
            'good' => 'blue',
            'recommended' => 'orange',
            default => 'red',
        };

        return [
            'label' => $this->translate('Fuzeo Queue'),
            'status' => $status,
            'badge' => ['label' => 'Fuzeo Queue', 'color' => $badge],
            'description' => '<p>' . $description . '</p>',
            'actions' => '',
            'test' => 'fuzeo_queue',
        ];
    }

    private function translate(string $text): string
    {
        return function_exists('__') ? (string) __($text, 'fuzeo-queue') : $text;
    }
}
