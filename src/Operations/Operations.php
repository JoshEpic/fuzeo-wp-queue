<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Operations;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Core\QueueManager;
use Fuzeo\Queue\Deployment\CompatibilityVerdict;
use Fuzeo\Queue\Deployment\ProcessExitCode;
use Fuzeo\Queue\Deployment\ReadinessReason;
use Fuzeo\Queue\Deployment\ReadinessReport;
use Fuzeo\Queue\Deployment\RuntimeCompatibility;
use Fuzeo\Queue\Drivers\FailureStore;
use Fuzeo\Queue\Drivers\MySql\MySqlDriver;
use Fuzeo\Queue\Drivers\ProvidesWorkerStore;
use Fuzeo\Queue\Drivers\Redis\RedisDriver;
use Fuzeo\Queue\Drivers\StatusAware;
use Fuzeo\Queue\Exceptions\DriverException;
use Fuzeo\Queue\Exceptions\UniqueConflictException;
use Fuzeo\Queue\Inspection\JobCatalog;
use Fuzeo\Queue\Inspection\JobPage;
use Fuzeo\Queue\Inspection\JobQuery;
use Fuzeo\Queue\Jobs\EnvelopeRedactor;
use Fuzeo\Queue\Jobs\JobState;
use Fuzeo\Queue\Metrics\MetricDimensions;
use Fuzeo\Queue\Metrics\MetricName;
use Fuzeo\Queue\Metrics\MetricRecorder;
use Fuzeo\Queue\Metrics\MetricResolution;
use Fuzeo\Queue\Metrics\MetricsQuery;
use Fuzeo\Queue\Metrics\MetricsRepository;
use Fuzeo\Queue\Metrics\MetricsRetention;
use Fuzeo\Queue\Orchestration\BatchState;
use Fuzeo\Queue\Orchestration\MemberStatus;
use Fuzeo\Queue\Persistence\SchemaOwner;
use Fuzeo\Queue\Redis\RedisScripts;
use Fuzeo\Queue\Runtime\NativeWordPressRuntime;
use Fuzeo\Queue\Runtime\PackageInfo;
use Fuzeo\Queue\Runtime\RuntimeGeneration;
use Fuzeo\Queue\Support\SecretRedactor;
use Fuzeo\Queue\Support\SystemClock;
use Fuzeo\Queue\Support\Ulid;
use Fuzeo\Queue\Worker\WorkerStatus;

final class Operations
{
    public const BULK_MAX = 50;

    public function __construct(
        private readonly QueueManager $manager,
        private readonly MetricRecorder $metrics,
        private readonly MetricsQuery $metricsQuery,
        private readonly MetricsRepository $metricsRepository,
        private readonly AuditStore $audit,
        private readonly JobCatalog $catalog,
        private readonly Clock $clock = new SystemClock(),
        private readonly HealthThresholds $thresholds = new HealthThresholds(),
        private readonly MetricsRetention $retention = new MetricsRetention(),
    ) {
    }

    public static function fromManager(QueueManager $manager): self
    {
        return $manager->operations();
    }

    public function recorder(): MetricRecorder
    {
        return $this->metrics;
    }

    public function metricsQuery(): MetricsQuery
    {
        return $this->metricsQuery;
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(Operator $operator, ?int $siteId = null): array
    {
        $siteId = $operator->requestedSiteFilter($siteId);
        $health = $this->queueHealth($operator, $siteId);
        $snapshots = $this->catalog->queueSnapshots($siteId);
        $pending = 0;
        $reserved = 0;
        $retrying = 0;
        $dead = 0;
        foreach ($snapshots as $snapshot) {
            $pending += $snapshot->pending;
            $reserved += $snapshot->reserved;
            $retrying += $snapshot->retrying;
            $dead += $snapshot->dead;
        }
        $now = $this->clock->now();
        $from = $now->modify('-1 hour');
        $dim = $this->dimension($siteId);
        $dispatched = $this->metricsQuery->ratePerMinute(MetricName::JOBS_DISPATCHED, $from, $now, $dim[0], $dim[1]);
        $completed = $this->metricsQuery->ratePerMinute(MetricName::JOBS_COMPLETED, $from, $now, $dim[0], $dim[1]);
        $workers = $this->workers($operator);
        $alive = 0;
        foreach ($workers as $worker) {
            if (($worker['health'] ?? '') !== 'stopped' && ($worker['health'] ?? '') !== 'stale') {
                $alive++;
            }
        }

        return [
            'health' => $health->toArray(),
            'metrics_degraded' => $this->metrics->isDegraded(),
            'pending' => $pending,
            'reserved' => $reserved,
            'retrying' => $retrying,
            'dead' => $dead,
            'processing_lag_seconds' => $this->catalog->oldestEligibleAgeSeconds(null, $siteId),
            'dispatch_per_minute' => $dispatched,
            'completion_per_minute' => $completed,
            'falling_behind' => $dispatched > $completed && $pending > 0,
            'workers_alive' => $alive,
            'empty' => $pending + $reserved + $retrying + $dead === 0 && $dispatched === 0.0,
            'no_workers' => $pending > 0 && $alive === 0,
            'timezone' => 'UTC',
            'polled' => true,
            'execution_mode' => $this->manager->execution()->mode()->value,
        ];
    }

    public function queueHealth(Operator $operator, ?int $siteId = null): HealthReport
    {
        unset($operator);
        $driver = $this->manager->driver();
        $health = $driver->health();
        if (!$health->ok) {
            return new HealthReport(HealthStatus::Critical, [$health->message ?? 'Driver unavailable.']);
        }
        $deploy = $this->manager->deployment()->snapshot();
        $now = $this->clock->now();
        $operational = 'normal';
        if ($deploy->isMaintenanceActive($now)) {
            $operational = 'maintenance';
        } elseif ($deploy->isDraining()) {
            $operational = 'draining';
        } elseif (
            $deploy->restartGeneration !== ''
            && $deploy->restartRequestedAt !== null
            && ($now->getTimestamp() - $deploy->restartRequestedAt->getTimestamp()) < 120
        ) {
            $operational = 'restarting';
        }
        $reasons = [];
        $status = HealthStatus::Healthy;
        $lag = $this->catalog->oldestEligibleAgeSeconds(null, $siteId);
        if ($lag !== null && $lag >= $this->thresholds->lagCriticalSeconds) {
            $status = HealthStatus::Critical;
            $reasons[] = 'Processing lag is ' . $lag . ' seconds.';
        } elseif ($lag !== null && $lag >= $this->thresholds->lagDegradedSeconds) {
            $status = HealthStatus::Degraded;
            $reasons[] = 'Processing lag is ' . $lag . ' seconds.';
        }
        $dead = 0;
        foreach ($this->catalog->queueSnapshots($siteId) as $snapshot) {
            $dead += $snapshot->dead;
        }
        if ($dead >= $this->thresholds->deadCritical) {
            $status = HealthStatus::Critical;
            $reasons[] = 'Dead-letter count is ' . $dead . '.';
        } elseif ($dead >= $this->thresholds->deadDegraded && $status !== HealthStatus::Critical) {
            $status = HealthStatus::Degraded;
            $reasons[] = 'Dead-letter count is ' . $dead . '.';
        }
        $pending = 0;
        foreach ($this->catalog->queueSnapshots($siteId) as $snapshot) {
            $pending += $snapshot->pending;
        }
        $alive = 0;
        if ($driver instanceof ProvidesWorkerStore) {
            foreach ($driver->workerStore()->all() as $row) {
                if (
                    !$driver->workerStore()->isStale($row, $this->thresholds->staleWorkerSeconds)
                    && ($row['status'] ?? '') !== WorkerStatus::Stopped->value
                ) {
                    $alive++;
                }
            }
        }
        if ($pending > 0 && $alive === 0) {
            if ($operational === 'normal') {
                $status = $pending >= $this->thresholds->backlogCritical ? HealthStatus::Critical : HealthStatus::Degraded;
                $reasons[] = 'Jobs are waiting, but no active Fuzeo Queue worker is detected.';
            } else {
                $reasons[] = 'Jobs are waiting while the fleet is ' . $operational . '. This is not a queue corruption.';
            }
        }
        if ($this->metrics->isDegraded()) {
            $reasons[] = 'Metrics storage is degraded.';
            if ($status === HealthStatus::Healthy) {
                $status = HealthStatus::Degraded;
            }
        }
        if ($operational !== 'normal') {
            $reasons[] = 'Intentional operational state: ' . $operational . '.';
            if ($status === HealthStatus::Critical) {
                $status = HealthStatus::Degraded;
            }
        }

        return new HealthReport($status, $reasons, 'queue', $operational);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function queues(Operator $operator, ?int $siteId = null): array
    {
        $siteId = $operator->requestedSiteFilter($siteId);
        $now = $this->clock->now();
        $from = $now->modify('-1 hour');
        $out = [];
        foreach ($this->catalog->queueSnapshots($siteId) as $snapshot) {
            $health = HealthStatus::Healthy;
            if ($snapshot->oldestPendingAgeSeconds !== null && $snapshot->oldestPendingAgeSeconds >= $this->thresholds->lagCriticalSeconds) {
                $health = HealthStatus::Critical;
            } elseif ($snapshot->oldestPendingAgeSeconds !== null && $snapshot->oldestPendingAgeSeconds >= $this->thresholds->lagDegradedSeconds) {
                $health = HealthStatus::Degraded;
            }
            $out[] = [
                'name' => $snapshot->queue,
                'pending' => $snapshot->pending,
                'reserved' => $snapshot->reserved,
                'retrying' => $snapshot->retrying,
                'dead' => $snapshot->dead,
                'cancelled' => $snapshot->cancelled,
                'oldest_waiting_seconds' => $snapshot->oldestPendingAgeSeconds,
                'dispatch_per_minute' => $this->metricsQuery->ratePerMinute(
                    MetricName::JOBS_DISPATCHED,
                    $from,
                    $now,
                    MetricDimensions::QUEUE,
                    $snapshot->queue
                ),
                'completion_per_minute' => $this->metricsQuery->ratePerMinute(
                    MetricName::JOBS_COMPLETED,
                    $from,
                    $now,
                    MetricDimensions::QUEUE,
                    $snapshot->queue
                ),
                'health' => $health->value,
            ];
        }

        return $out;
    }

    public function jobs(Operator $operator, JobQuery $query): JobPage
    {
        $siteId = $operator->requestedSiteFilter($query->siteId);
        $bounded = new JobQuery(
            $query->state,
            $query->queue,
            $query->jobType,
            $query->origin,
            $siteId,
            $query->jobId,
            $query->tag,
            $query->createdAfter,
            $query->createdBefore,
            $query->limit,
            $query->offset,
        );

        return $this->catalog->list($bounded);
    }

    /**
     * @return array<string, mixed>
     */
    public function job(Operator $operator, string $jobId, bool $includePayload = false): array
    {
        $inspection = $this->catalog->inspect($jobId);
        $envelope = $inspection->envelope;
        $operator->assertViewContext($envelope->context);
        $summary = EnvelopeRedactor::summarize($envelope, false);
        $wait = $inspection->reservedAt !== null
            ? max(0, $inspection->reservedAt->getTimestamp() - $envelope->availableAt->getTimestamp())
            : null;
        $runtime = null;
        if ($inspection->reservedAt !== null && $envelope->state === JobState::Reserved) {
            $runtime = max(0, $this->clock->now()->getTimestamp() - $inspection->reservedAt->getTimestamp());
        }
        $payload = null;
        if ($includePayload && $operator->canPayload($envelope->context->siteId)) {
            $payload = (new SecretRedactor())->redactMap($envelope->payload);
        }
        $attempts = [];
        $driver = $this->manager->driver();
        if ($driver instanceof FailureStore && $operator->canTrace($envelope->context->siteId)) {
            foreach ($driver->attemptsFor($jobId) as $attempt) {
                $attempts[] = [
                    'attempt' => $attempt->attempt,
                    'outcome' => $attempt->outcome,
                    'class' => $attempt->failureClass,
                    'message' => $attempt->sanitizedMessage,
                    'trace' => $attempt->sanitizedTrace,
                    'will_retry' => $attempt->willRetry,
                    'failed_at' => $attempt->failedAt->format(\DateTimeInterface::ATOM),
                ];
            }
        } elseif ($driver instanceof FailureStore) {
            foreach ($driver->attemptsFor($jobId) as $attempt) {
                $attempts[] = [
                    'attempt' => $attempt->attempt,
                    'outcome' => $attempt->outcome,
                    'class' => $attempt->failureClass,
                    'message' => $attempt->sanitizedMessage,
                    'will_retry' => $attempt->willRetry,
                    'failed_at' => $attempt->failedAt->format(\DateTimeInterface::ATOM),
                ];
            }
        }

        return [
            'summary' => $summary,
            'state' => $envelope->state->value,
            'priority' => $envelope->priority,
            'attempts' => $envelope->attempt,
            'max_attempts' => $envelope->maxAttempts,
            'available_at' => $envelope->availableAt->format(\DateTimeInterface::ATOM),
            'created_at' => $envelope->createdAt->format(\DateTimeInterface::ATOM),
            'wait_seconds' => $wait,
            'runtime_seconds' => $runtime,
            'worker_id' => $inspection->workerId,
            'reservation_age_seconds' => $inspection->reservedAt !== null
                ? max(0, $this->clock->now()->getTimestamp() - $inspection->reservedAt->getTimestamp())
                : null,
            'lease_expires_at' => $inspection->leaseExpiresAt?->format(\DateTimeInterface::ATOM),
            'cancel_requested' => $inspection->cancelRequested,
            'chain_id' => $envelope->chainId,
            'batch_id' => $envelope->batchId,
            'unique_key' => $envelope->uniqueKey,
            'tags' => $envelope->tags,
            'metadata' => $envelope->metadata,
            'payload' => $payload,
            'payload_visible' => $payload !== null,
            'failure_history' => $attempts,
            'can_cancel_immediately' => $envelope->state === JobState::Pending,
            'can_request_cancel' => $envelope->state === JobState::Reserved,
            'site_label' => $this->siteLabel($envelope->context->siteId, $envelope->context->scope->value),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function retry(Operator $operator, string $jobId): array
    {
        $inspection = $this->catalog->inspect($jobId);
        $operator->assertRetry($inspection->envelope->context->siteId);
        $driver = $this->manager->driver();
        if (!$driver instanceof FailureStore) {
            throw new DriverException('Retry requires a failure store.');
        }
        try {
            $revived = $driver->revive($jobId);
        } catch (UniqueConflictException $exception) {
            throw $exception;
        }
        $this->manager->orchestrator()->onRevived($revived);
        $this->audit($operator, 'audit', 'job.retry', 'job', $jobId, $revived->context->siteId, $revived->context->networkId);
        $this->metrics->increment(MetricName::JOBS_RETRIED, 1, MetricDimensions::fromEnvelope($revived, $this->manager->config()->driver));

        return ['job_id' => $revived->jobId, 'state' => $revived->state->value];
    }

    /**
     * @param list<string> $ids
     * @return list<array<string, mixed>>
     */
    public function retryMany(Operator $operator, array $ids): array
    {
        $ids = array_slice($ids, 0, self::BULK_MAX);
        $out = [];
        foreach ($ids as $id) {
            $out[] = $this->retry($operator, $id);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(Operator $operator, string $jobId): array
    {
        $inspection = $this->catalog->inspect($jobId);
        $operator->assertManage($inspection->envelope->context->siteId);
        $result = $this->manager->orchestrator()->cancelJob($jobId);
        $this->audit($operator, 'audit', 'job.cancel', 'job', $jobId, $inspection->envelope->context->siteId, $inspection->envelope->context->networkId);

        return ['job_id' => $jobId, 'outcome' => $result->outcome, 'state' => $result->state?->value];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function workers(Operator $operator): array
    {
        unset($operator);
        $driver = $this->manager->driver();
        if (!$driver instanceof ProvidesWorkerStore) {
            return [];
        }
        $out = [];
        foreach ($driver->workerStore()->all() as $row) {
            $stale = $driver->workerStore()->isStale($row, $this->thresholds->staleWorkerSeconds);
            $status = (string) ($row['status'] ?? '');
            $health = $status === WorkerStatus::Stopped->value ? 'stopped' : ($stale ? 'stale' : $status);
            $heartbeat = (string) ($row['last_heartbeat_at'] ?? '');
            $age = null;
            if ($heartbeat !== '') {
                $age = max(0, $this->clock->now()->getTimestamp() - (new \DateTimeImmutable($heartbeat))->getTimestamp());
            }
            $out[] = [
                'worker_id' => (string) ($row['worker_id'] ?? ''),
                'hostname' => (string) ($row['hostname'] ?? ''),
                'pid' => (string) ($row['pid'] ?? ''),
                'started_at' => (string) ($row['started_at'] ?? ''),
                'queues' => (string) ($row['queues'] ?? ''),
                'status' => $status,
                'health' => $health,
                'heartbeat_at' => $heartbeat,
                'heartbeat_age_seconds' => $age,
                'memory_bytes' => (int) ($row['memory_bytes'] ?? 0),
                'processed_count' => (int) ($row['processed_count'] ?? 0),
                'current_job_id' => $row['current_job_id'] ?? null,
                'runtime_generation' => $row['runtime_generation'] ?? null,
                'deployment_generation' => $row['deployment_generation'] ?? ($row['runtime_generation'] ?? null),
                'schema_version' => isset($row['schema_version']) ? (int) $row['schema_version'] : null,
                'recycle_reason' => $row['recycle_reason'] ?? null,
                'runtime_version' => (string) ($row['runtime_version'] ?? ''),
                'process_type' => (string) ($row['process_type'] ?? 'persistent'),
                'driver' => $this->manager->config()->driver,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function schedules(Operator $operator, ?int $siteId = null): array
    {
        $siteId = $operator->requestedSiteFilter($siteId);
        $out = [];
        foreach ($this->manager->schedules()->all() as $schedule) {
            if ($siteId !== null && $schedule->context->siteId !== $siteId) {
                continue;
            }
            $operator->assertViewContext($schedule->context);
            $out[] = [
                'id' => $schedule->scheduleId,
                'name' => $schedule->name,
                'origin' => $schedule->origin->package,
                'job_type' => $schedule->jobType,
                'expression' => $schedule->expression->type->value . ' ' . $schedule->expression->value,
                'timezone' => $schedule->timezone,
                'enabled' => $schedule->enabled,
                'blocked' => $schedule->blockedReason,
                'next_run' => $schedule->nextRunAt->format(\DateTimeInterface::ATOM),
                'last_run' => $schedule->lastRunAt?->format(\DateTimeInterface::ATOM),
                'overlap' => $schedule->overlap->value,
                'catch_up' => $schedule->catchUp->value,
                'site_id' => $schedule->context->siteId,
                'scope' => $schedule->context->scope->value,
            ];
        }

        return $out;
    }

    public function enableSchedule(Operator $operator, string $id): void
    {
        $schedule = $this->manager->schedules()->get($id);
        if ($schedule === null) {
            throw new DriverException('Unknown schedule ' . $id . '.');
        }
        $operator->assertManage($schedule->context->siteId);
        $this->manager->schedules()->enable($id);
        $this->audit($operator, 'audit', 'schedule.enable', 'schedule', $id, $schedule->context->siteId, $schedule->context->networkId);
    }

    public function disableSchedule(Operator $operator, string $id): void
    {
        $schedule = $this->manager->schedules()->get($id);
        if ($schedule === null) {
            throw new DriverException('Unknown schedule ' . $id . '.');
        }
        $operator->assertManage($schedule->context->siteId);
        $this->manager->schedules()->disable($id);
        $this->audit($operator, 'audit', 'schedule.disable', 'schedule', $id, $schedule->context->siteId, $schedule->context->networkId);
    }

    public function runSchedule(Operator $operator, string $id): int
    {
        $schedule = $this->manager->schedules()->get($id);
        if ($schedule === null) {
            throw new DriverException('Unknown schedule ' . $id . '.');
        }
        $operator->assertManage($schedule->context->siteId);
        $n = $this->manager->scheduler()->runOne($id);
        $this->audit($operator, 'audit', 'schedule.run', 'schedule', $id, $schedule->context->siteId, $schedule->context->networkId);

        return $n;
    }

    public function schedulerHealth(Operator $operator): HealthReport
    {
        unset($operator);
        $schedules = $this->manager->schedules()->all();
        if ($schedules === []) {
            return new HealthReport(HealthStatus::Healthy, ['No persistent schedules are registered.'], 'scheduler');
        }
        $heartbeats = $this->manager->schedules()->store()->schedulers();
        if ($heartbeats === []) {
            return new HealthReport(HealthStatus::Degraded, ['No scheduler heartbeat is present.'], 'scheduler');
        }
        $latest = $heartbeats[0]['last_heartbeat_at'] ?? '';
        if (is_string($latest) && $latest !== '') {
            $age = $this->clock->now()->getTimestamp() - (new \DateTimeImmutable($latest))->getTimestamp();
            if ($age > $this->thresholds->schedulerStaleSeconds) {
                return new HealthReport(HealthStatus::Degraded, ['Scheduler heartbeat is ' . $age . ' seconds old.'], 'scheduler');
            }
        }
        $blocked = 0;
        foreach ($schedules as $schedule) {
            if ($schedule->blockedReason !== null && $schedule->blockedReason !== '') {
                $blocked++;
            }
        }
        if ($blocked > 0) {
            return new HealthReport(HealthStatus::Degraded, [$blocked . ' schedule(s) are blocked.'], 'scheduler');
        }

        return new HealthReport(HealthStatus::Healthy, [], 'scheduler');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function chains(Operator $operator, ?int $siteId = null): array
    {
        $siteId = $operator->requestedSiteFilter($siteId);
        $out = [];
        foreach ($this->manager->orchestrator()->store()->listChains(50) as $chain) {
            if ($siteId !== null && $chain->context->siteId !== $siteId) {
                continue;
            }
            $operator->assertViewContext($chain->context);
            $out[] = [
                'id' => $chain->chainId,
                'origin' => $chain->origin->package,
                'state' => $chain->state->value,
                'current_step' => $chain->currentStep,
                'total_steps' => $chain->totalSteps,
                'failed_step' => $chain->failedStep,
                'site_id' => $chain->context->siteId,
                'created_at' => $chain->createdAt->format(\DateTimeInterface::ATOM),
                'duration_seconds' => $this->duration($chain->createdAt, $chain->completedAt ?? $chain->failedAt ?? $chain->cancelledAt),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function chain(Operator $operator, string $id): array
    {
        $chain = $this->manager->orchestrator()->store()->getChain($id);
        if ($chain === null) {
            throw new DriverException('Unknown chain ' . $id . '.');
        }
        $operator->assertViewContext($chain->context);
        $steps = [];
        foreach ($this->manager->orchestrator()->store()->steps($id) as $step) {
            $mark = match ($step->status) {
                MemberStatus::Completed => 'done',
                MemberStatus::Dead, MemberStatus::UniqueConflict => 'failed',
                MemberStatus::Dispatched => 'current',
                default => 'pending',
            };
            $steps[] = [
                'number' => $step->stepNumber,
                'job_type' => $step->jobType,
                'status' => $step->status->value,
                'mark' => $mark,
                'job_id' => $step->jobId,
            ];
        }

        return [
            'id' => $chain->chainId,
            'state' => $chain->state->value,
            'origin' => $chain->origin->package,
            'steps' => $steps,
            'failed_step' => $chain->failedStep,
            'site_id' => $chain->context->siteId,
        ];
    }

    public function retryChain(Operator $operator, string $id): void
    {
        $chain = $this->manager->orchestrator()->store()->getChain($id);
        if ($chain === null) {
            throw new DriverException('Unknown chain ' . $id . '.');
        }
        $operator->assertRetry($chain->context->siteId);
        $this->manager->orchestrator()->retryChain($id);
        $this->audit($operator, 'audit', 'chain.retry', 'chain', $id, $chain->context->siteId, $chain->context->networkId);
    }

    public function cancelChain(Operator $operator, string $id): void
    {
        $chain = $this->manager->orchestrator()->store()->getChain($id);
        if ($chain === null) {
            throw new DriverException('Unknown chain ' . $id . '.');
        }
        $operator->assertManage($chain->context->siteId);
        $this->manager->orchestrator()->cancelChain($id);
        $this->audit($operator, 'audit', 'chain.cancel', 'chain', $id, $chain->context->siteId, $chain->context->networkId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function batches(Operator $operator, ?int $siteId = null): array
    {
        $siteId = $operator->requestedSiteFilter($siteId);
        $out = [];
        foreach ($this->manager->orchestrator()->store()->listBatches(50) as $batch) {
            if ($siteId !== null && $batch->context->siteId !== $siteId) {
                continue;
            }
            $operator->assertViewContext($batch->context);
            $done = $batch->completedJobs + $batch->failedJobs + $batch->cancelledJobs;
            $out[] = [
                'id' => $batch->batchId,
                'name' => $batch->name,
                'state' => $batch->state->value,
                'progress' => $done,
                'total' => $batch->totalJobs,
                'completed' => $batch->completedJobs,
                'failed' => $batch->failedJobs,
                'cancelled' => $batch->cancelledJobs,
                'origin' => $batch->origin->package,
                'site_id' => $batch->context->siteId,
                'reconciling' => $batch->state === BatchState::Active && $done !== $batch->totalJobs,
                'duration_seconds' => $this->duration($batch->createdAt, $batch->completedAt ?? $batch->failedAt ?? $batch->cancelledAt),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function batch(Operator $operator, string $id, int $offset = 0, int $limit = 25, ?string $status = null): array
    {
        $batch = $this->manager->orchestrator()->store()->getBatch($id);
        if ($batch === null) {
            throw new DriverException('Unknown batch ' . $id . '.');
        }
        $operator->assertViewContext($batch->context);
        $limit = max(1, min(100, $limit));
        $members = [];
        $source = $this->manager->orchestrator()->store()->dispatchedMembers($id, 500);
        $filtered = [];
        foreach ($source as $member) {
            if ($status !== null && $member->status->value !== $status) {
                continue;
            }
            $filtered[] = $member;
        }
        foreach (array_slice($filtered, $offset, $limit) as $member) {
            $members[] = [
                'index' => $member->memberIndex,
                'job_type' => $member->jobType,
                'status' => $member->status->value,
                'job_id' => $member->jobId,
            ];
        }
        $done = $batch->completedJobs + $batch->failedJobs + $batch->cancelledJobs;

        return [
            'id' => $batch->batchId,
            'name' => $batch->name,
            'state' => $batch->state->value,
            'progress' => $done,
            'total' => $batch->totalJobs,
            'completed' => $batch->completedJobs,
            'failed' => $batch->failedJobs,
            'cancelled' => $batch->cancelledJobs,
            'then_job_id' => $batch->thenJobId,
            'catch_job_id' => $batch->catchJobId,
            'reconciling' => $batch->state === BatchState::Active && $done > $batch->totalJobs,
            'members' => $members,
            'member_offset' => $offset,
            'member_limit' => $limit,
            'member_total' => count($filtered),
        ];
    }

    public function cancelBatch(Operator $operator, string $id): void
    {
        $batch = $this->manager->orchestrator()->store()->getBatch($id);
        if ($batch === null) {
            throw new DriverException('Unknown batch ' . $id . '.');
        }
        $operator->assertManage($batch->context->siteId);
        $this->manager->orchestrator()->cancelBatch($id);
        $this->audit($operator, 'audit', 'batch.cancel', 'batch', $id, $batch->context->siteId, $batch->context->networkId);
    }

    /**
     * @return array<string, mixed>
     */
    public function metrics(Operator $operator, string $period = '1h', ?string $queue = null, ?string $jobType = null, ?string $origin = null, ?int $siteId = null): array
    {
        $siteId = $operator->requestedSiteFilter($siteId);
        [$from, $to, $resolution] = $this->window($period);
        $dim = MetricDimensions::NONE;
        $value = '';
        if ($queue !== null) {
            $dim = MetricDimensions::QUEUE;
            $value = $queue;
        } elseif ($jobType !== null) {
            $dim = MetricDimensions::JOB_TYPE;
            $value = $jobType;
        } elseif ($origin !== null) {
            $dim = MetricDimensions::ORIGIN;
            $value = $origin;
        } elseif ($siteId !== null) {
            $dim = MetricDimensions::SITE;
            $value = (string) $siteId;
        }

        return [
            'period' => $period,
            'resolution' => $resolution->value,
            'timezone' => 'UTC',
            'degraded' => $this->metrics->isDegraded(),
            'dispatched' => $this->points(MetricName::JOBS_DISPATCHED, $resolution, $from, $to, $dim, $value),
            'completed' => $this->points(MetricName::JOBS_COMPLETED, $resolution, $from, $to, $dim, $value),
            'failed' => $this->points(MetricName::JOBS_FAILED, $resolution, $from, $to, $dim, $value),
            'retried' => $this->points(MetricName::JOBS_RETRIED, $resolution, $from, $to, $dim, $value),
            'runtime' => $this->metricsQuery->timing(MetricName::RUNTIME_MS, $resolution, $from, $to, $dim, $value),
            'wait' => $this->metricsQuery->timing(MetricName::WAIT_MS, $resolution, $from, $to, $dim, $value),
        ];
    }

    public function recordCronWorker(): void
    {
        try {
            $this->manager->execution()->recordCronWorker();
        } catch (\Throwable) {
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function diagnostics(Operator $operator): array
    {
        unset($operator);
        $driver = $this->manager->driver();
        $health = $driver->health();
        $config = $this->manager->config();
        $wp = new \Fuzeo\Queue\Runtime\NativeWordPressRuntime();
        $as = function_exists('as_schedule_single_action') || class_exists('ActionScheduler');
        $cronPresent = function_exists('_get_cron_array') || function_exists('wp_next_scheduled');
        $base = [
            'package_version' => PackageInfo::VERSION,
            'compatibility_series' => PackageInfo::COMPATIBILITY_SERIES,
            'schema_version' => SchemaOwner::CURRENT_VERSION,
            'lua_script_version' => RedisScripts::VERSION,
            'driver' => $health->driver,
            'driver_ok' => $health->ok,
            'driver_message' => $health->message,
            'redis_version' => (string) ($health->details['redis_version'] ?? ''),
            'redis_policy' => (string) ($health->details['maxmemory_policy'] ?? ''),
            'mysql_skip_locked' => $health->details['skip_locked'] ?? null,
            'workers' => $this->workers(Operator::cli()),
            'scheduler' => $this->schedulerHealth(Operator::cli())->toArray(),
            'runtime_generation' => (new RuntimeGeneration($wp))->current(),
            'deployment' => $this->deploymentStatus(Operator::cli()),
            'multisite' => $wp->isMultisite(),
            'object_cache_dropin' => $wp->objectCacheDropInPresent(),
            'metrics_degraded' => $this->metrics->isDegraded(),
            'action_scheduler_detected' => $as,
            'wp_cron_available' => $cronPresent,
            'wp_cron_automatic_spawning_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            'retention' => [
                'minute_hours' => $this->retention->minuteHours,
                'hour_days' => $this->retention->hourDays,
                'day_days' => $this->retention->dayDays,
            ],
            'default_queue' => $config->defaultQueue,
            'payloads_included' => false,
        ];
        try {
            $base['execution'] = $this->manager->execution()->snapshot();
        } catch (\Throwable) {
        }

        try {
            return array_merge($base, $this->manager->interop()->diagnostics());
        } catch (\Throwable) {
            return $base;
        }
    }

    public function prune(Operator $operator, int $batchSize = 500): int
    {
        $operator->assertManage($operator->currentSiteId);
        $deleted = $this->metricsRepository->prune($this->clock->now(), $this->retention, $batchSize);
        $deleted += $this->audit->prune($this->clock->now()->modify('-30 days'), $batchSize);

        return $deleted;
    }

    public function reconcile(Operator $operator, int $limit = 100): int
    {
        $operator->assertManage($operator->currentSiteId);
        $n = $this->manager->orchestrator()->reconcile($limit);
        $this->audit($operator, 'audit', 'reconcile', 'queue', 'runtime', $operator->currentSiteId, 1);

        return $n;
    }

    public function assertDispatchAllowed(): void
    {
        $report = $this->readiness(Operator::cli());
        if ($report->canDispatch) {
            return;
        }
        throw new DriverException('Queue dispatch refused: ' . $report->reason);
    }

    public function compatibility(): CompatibilityVerdict
    {
        $driver = $this->manager->driver();
        $health = $driver->health();
        $kernel = is_array($GLOBALS['fuzeo_queue_kernel'] ?? null) ? $GLOBALS['fuzeo_queue_kernel'] : [];
        $highest = '';
        $candidates = is_array($kernel['candidates'] ?? null) ? $kernel['candidates'] : [];
        foreach ($candidates as $row) {
            if (is_array($row) && isset($row['version']) && is_string($row['version'])) {
                if ($highest === '' || version_compare($row['version'], $highest, '>')) {
                    $highest = $row['version'];
                }
            }
        }
        $storedSchema = SchemaOwner::CURRENT_VERSION;
        $exact = false;
        $enforceLua = false;
        $storedLua = RedisScripts::VERSION;
        if ($driver instanceof MySqlDriver) {
            $exact = true;
            $storedSchema = $this->manager->migrations()->currentVersion();
        } elseif ($driver instanceof RedisDriver) {
            $enforceLua = true;
            $storedLua = $driver->storedLuaVersion();
        }

        return (new RuntimeCompatibility(
            loadedSchema: SchemaOwner::CURRENT_VERSION,
            storedSchema: $storedSchema,
            targetSchema: SchemaOwner::CURRENT_VERSION,
            loadedSeries: PackageInfo::COMPATIBILITY_SERIES,
            requiredSeries: PackageInfo::COMPATIBILITY_SERIES,
            loadedLua: RedisScripts::VERSION,
            storedLua: $storedLua,
            enforceLua: $enforceLua,
            driverOk: $health->ok && $health->driver !== 'unavailable',
            exactSchema: $exact,
            state: $this->manager->deployment()->snapshot(),
            now: $this->clock->now(),
            loadedPackage: PackageInfo::VERSION,
            highestCandidateVersion: $highest,
        ))->evaluate();
    }

    public function readiness(Operator $operator): ReadinessReport
    {
        unset($operator);
        $verdict = $this->compatibility();
        $reason = $verdict->primaryReason;
        $ready = $verdict->canReserve && $reason === ReadinessReason::Ready->value;
        if ($verdict->canReserve && $verdict->canDispatch && $verdict->canBoot && !$verdict->mustMigrate) {
            $ready = $reason === ReadinessReason::Ready->value || $reason === ReadinessReason::RestartRequested->value;
        }
        $exit = $ready ? ProcessExitCode::OK : ProcessExitCode::fromReadinessReason($reason);
        $generation = $this->deploymentGeneration();

        return new ReadinessReport(
            $ready,
            $reason,
            $verdict->reasons,
            $verdict->canDispatch,
            $verdict->canReserve,
            $exit,
            [
                'package_version' => PackageInfo::VERSION,
                'loaded_class_version' => PackageInfo::VERSION,
                'compatibility_series' => PackageInfo::COMPATIBILITY_SERIES,
                'schema_version' => SchemaOwner::CURRENT_VERSION,
                'lua_script_version' => RedisScripts::VERSION,
                'deployment_generation' => $generation->current(),
                'compatibility' => $verdict->toArray(),
                'deployment' => $this->manager->deployment()->snapshot()->toArray($this->clock->now()),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function deploymentStatus(Operator $operator): array
    {
        unset($operator);
        $generation = $this->deploymentGeneration();
        $workers = $this->workers(Operator::cli());
        $fleet = [];
        $stale = [];
        $current = $generation->current();
        foreach ($workers as $worker) {
            $gen = (string) ($worker['deployment_generation'] ?? $worker['runtime_generation'] ?? '');
            $key = ($worker['runtime_version'] ?? '') . ':' . substr($gen, 0, 12);
            if (!isset($fleet[$key])) {
                $fleet[$key] = ['generation' => $gen, 'runtime_version' => $worker['runtime_version'] ?? '', 'workers' => 0];
            }
            $fleet[$key]['workers']++;
            $health = (string) ($worker['health'] ?? '');
            if ($gen !== '' && $gen !== $current && $health !== 'stopped') {
                $stale[] = $worker;
            }
        }
        $schedulers = $this->manager->schedules()->store()->schedulers();
        $snap = $this->manager->deployment()->snapshot();
        $aliveNew = 0;
        foreach ($workers as $worker) {
            $started = (string) ($worker['started_at'] ?? '');
            $health = (string) ($worker['health'] ?? '');
            if ($health === 'stopped' || $health === 'stale') {
                continue;
            }
            if ($snap->restartRequestedAt === null || $started === '') {
                $aliveNew++;
                continue;
            }
            try {
                $at = new \DateTimeImmutable($started);
                if ($at->getTimestamp() >= $snap->restartRequestedAt->getTimestamp()) {
                    $aliveNew++;
                }
            } catch (\Exception) {
                $aliveNew++;
            }
        }

        return [
            'generation' => $generation->describe(),
            'state' => $snap->toArray($this->clock->now()),
            'readiness' => $this->readiness(Operator::cli())->toArray(),
            'fleet' => array_values($fleet),
            'stale_processes' => $stale,
            'schedulers' => count($schedulers),
            'replacements_online' => $aliveNew,
            'expected_worker_count' => $this->manager->config()->expectedWorkerCount,
            'no_process_manager' => $snap->restartRequestedAt !== null && $aliveNew === 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function requestRestart(Operator $operator): array
    {
        $operator->assertFleetManage();
        $generation = Ulid::generate();
        $this->manager->deployment()->requestRestart($generation, $this->clock->now());
        $this->audit($operator, 'audit', 'restart.requested', 'deployment', $generation, $operator->currentSiteId, 1);
        $this->recordEvent('worker.recycle_requested', 'deployment', $generation);
        $workers = $this->livingWorkerCount();
        $schedulers = count($this->manager->schedules()->store()->schedulers());

        return [
            'restart_generation' => $generation,
            'workers_expected_to_recycle' => $workers,
            'schedulers_expected_to_recycle' => $schedulers,
            'note' => 'Fuzeo Queue requested a recycle. Replacement processes are started by your process manager, not by Queue.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function requestDrain(Operator $operator): array
    {
        $operator->assertFleetManage();
        $generation = Ulid::generate();
        $this->manager->deployment()->requestDrain($generation, $this->clock->now());
        $this->audit($operator, 'audit', 'drain.requested', 'deployment', $generation, $operator->currentSiteId, 1);
        $this->recordEvent('worker.drain_requested', 'deployment', $generation);

        return [
            'drain_generation' => $generation,
            'requested_at' => $this->clock->now()->format(\DateTimeInterface::ATOM),
            'note' => 'Workers will stop reserving new jobs. Active jobs are not killed.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelDrain(Operator $operator): array
    {
        $operator->assertFleetManage();
        $this->manager->deployment()->cancelDrain();
        $this->audit($operator, 'audit', 'drain.cancelled', 'deployment', 'fleet', $operator->currentSiteId, 1);

        return ['cancelled' => true];
    }

    /**
     * @return array<string, mixed>
     */
    public function drainProgress(): array
    {
        $snap = $this->manager->deployment()->snapshot();
        $active = [];
        $driver = $this->manager->driver();
        if ($driver instanceof ProvidesWorkerStore) {
            foreach ($driver->workerStore()->all() as $row) {
                $status = (string) ($row['status'] ?? '');
                if (in_array($status, [WorkerStatus::Working->value, WorkerStatus::Running->value], true)) {
                    $job = (string) ($row['current_job_id'] ?? '');
                    $started = (string) ($row['started_at'] ?? '');
                    $elapsed = 0;
                    if ($started !== '') {
                        try {
                            $elapsed = max(0, $this->clock->now()->getTimestamp() - (new \DateTimeImmutable($started))->getTimestamp());
                        } catch (\Exception) {
                        }
                    }
                    $active[] = [
                        'worker_id' => (string) ($row['worker_id'] ?? ''),
                        'job_id' => $job,
                        'status' => $status,
                        'runtime_seconds' => $elapsed,
                    ];
                }
            }
        }
        $reserved = 0;
        if ($driver instanceof StatusAware) {
            $counts = $driver->countsByState();
            $reserved = (int) ($counts['reserved'] ?? 0);
        }
        $complete = $snap->isDraining() && $reserved === 0 && $active === [];
        $elapsed = 0;
        if ($snap->drainRequestedAt !== null) {
            $elapsed = max(0, $this->clock->now()->getTimestamp() - $snap->drainRequestedAt->getTimestamp());
        }

        return [
            'draining' => $snap->isDraining(),
            'complete' => $complete,
            'reserved' => $reserved,
            'active' => $active,
            'elapsed_seconds' => $elapsed,
            'pending' => $this->pendingCount(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function migrate(Operator $operator, bool $check = false): array
    {
        $operator->assertFleetManage();
        $migrations = \Fuzeo\Queue\Runtime\Coordinator::packageMigrations();
        $status = $this->manager->migrations()->status($migrations);
        if ($check) {
            return $status + ['check' => true];
        }
        $owner = Ulid::generate();
        $ttl = max(30, $this->manager->config()->maintenanceLeaseSeconds);
        $expires = $this->clock->now()->modify('+' . $ttl . ' seconds');
        if (!$this->manager->deployment()->enterMaintenance($owner, $expires, 'schema_migration')) {
            throw new DriverException('Another deployment transition owns maintenance state.');
        }
        $this->audit($operator, 'audit', 'migration.started', 'schema', (string) $status['current'], $operator->currentSiteId, 1);
        try {
            $result = $this->manager->migrations()->run($migrations);
            if ($result->lockedOut) {
                $this->audit($operator, 'audit', 'migration.locked_out', 'schema', (string) $status['current'], $operator->currentSiteId, 1);
                throw new DriverException('Migration lock is held by another process.');
            }
            $this->audit($operator, 'audit', 'migration.completed', 'schema', (string) $result->toVersion, $operator->currentSiteId, 1, [
                'from' => $result->fromVersion,
                'to' => $result->toVersion,
            ]);
            $this->recordEvent('migration.completed', 'schema', (string) $result->toVersion);

            return [
                'from' => $result->fromVersion,
                'to' => $result->toVersion,
                'applied' => $result->applied,
                'locked_out' => false,
            ];
        } catch (DriverException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->audit($operator, 'audit', 'migration.failed', 'schema', (string) $status['current'], $operator->currentSiteId, 1);
            throw new DriverException('Migration failed. Schema version was not advanced. ' . $exception->getMessage(), 0, $exception);
        } finally {
            $this->manager->deployment()->releaseMaintenance($owner);
        }
    }

    public function deploymentGeneration(): \Fuzeo\Queue\Deployment\DeploymentGeneration
    {
        $wp = new NativeWordPressRuntime();

        return new \Fuzeo\Queue\Deployment\DeploymentGeneration(
            new RuntimeGeneration($wp),
            $this->manager->config()->deploymentId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function waitForRestart(int $timeoutSeconds, int $expected = 0): array
    {
        $deadline = time() + max(1, $timeoutSeconds);
        $snap = $this->manager->deployment()->snapshot();
        $oldGone = false;
        $replacements = 0;
        do {
            $status = $this->deploymentStatus(Operator::cli());
            $oldGone = $this->oldProcessesGone($snap);
            $replacements = (int) $status['replacements_online'];
            if ($oldGone && ($expected === 0 || $replacements >= $expected)) {
                break;
            }
            usleep(200000);
        } while (time() < $deadline);

        return [
            'old_processes_exited' => $oldGone,
            'replacements_online' => $replacements,
            'expected_worker_count' => $expected,
            'no_process_manager' => $oldGone && $replacements === 0,
            'timed_out' => !$oldGone || ($expected > 0 && $replacements < $expected),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function waitForDrain(int $timeoutSeconds): array
    {
        $deadline = time() + max(1, $timeoutSeconds);
        $progress = $this->drainProgress();
        while (time() < $deadline) {
            $progress = $this->drainProgress();
            if ($progress['complete']) {
                return $progress + ['timed_out' => false];
            }
            usleep(200000);
        }

        return $progress + ['timed_out' => true];
    }

    private function livingWorkerCount(): int
    {
        $n = 0;
        foreach ($this->workers(Operator::cli()) as $worker) {
            $health = (string) ($worker['health'] ?? '');
            if ($health !== 'stopped' && $health !== 'stale') {
                $n++;
            }
        }

        return $n;
    }

    private function pendingCount(): int
    {
        $n = 0;
        foreach ($this->catalog->queueSnapshots(null) as $snapshot) {
            $n += $snapshot->pending;
        }

        return $n;
    }

    private function oldProcessesGone(\Fuzeo\Queue\Deployment\DeploymentState $snap): bool
    {
        if ($snap->restartRequestedAt === null) {
            return true;
        }
        $driver = $this->manager->driver();
        if (!$driver instanceof ProvidesWorkerStore) {
            return true;
        }
        foreach ($driver->workerStore()->all() as $row) {
            $status = (string) ($row['status'] ?? '');
            if ($status === WorkerStatus::Stopped->value) {
                continue;
            }
            if ($driver->workerStore()->isStale($row, $this->thresholds->staleWorkerSeconds)) {
                continue;
            }
            $started = (string) ($row['started_at'] ?? '');
            if ($started === '') {
                return false;
            }
            try {
                $at = new \DateTimeImmutable($started);
            } catch (\Exception) {
                return false;
            }
            if ($at->getTimestamp() < $snap->restartRequestedAt->getTimestamp()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, scalar|null> $details
     */
    public function recordEvent(string $action, string $resourceType = 'runtime', string $resourceId = '', array $details = []): void
    {
        try {
            $this->audit->record(new AuditEvent(
                $this->clock->now(),
                'event',
                $action,
                0,
                $resourceType,
                $resourceId,
                1,
                0,
                $details,
            ));
        } catch (\Throwable) {
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function dimension(?int $siteId): array
    {
        if ($siteId === null) {
            return [MetricDimensions::NONE, ''];
        }

        return [MetricDimensions::SITE, (string) $siteId];
    }

    /**
     * @return list<array{t: string, v: int}>
     */
    private function points(
        string $metric,
        MetricResolution $resolution,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $dim,
        string $value,
    ): array {
        $out = [];
        foreach ($this->metricsQuery->series($metric, $resolution, $from, $to, $dim, $value) as $sample) {
            $out[] = ['t' => $sample->bucket->format(\DateTimeInterface::ATOM), 'v' => $sample->count];
        }

        return $out;
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: MetricResolution}
     */
    private function window(string $period): array
    {
        $now = $this->clock->now();
        return match ($period) {
            '1h' => [$now->modify('-1 hour'), $now, MetricResolution::Minute],
            '6h' => [$now->modify('-6 hours'), $now, MetricResolution::Minute],
            '24h' => [$now->modify('-24 hours'), $now, MetricResolution::Hour],
            '7d' => [$now->modify('-7 days'), $now, MetricResolution::Hour],
            '30d' => [$now->modify('-30 days'), $now, MetricResolution::Day],
            default => [$now->modify('-1 hour'), $now, MetricResolution::Minute],
        };
    }

    private function duration(\DateTimeImmutable $start, ?\DateTimeImmutable $end): int
    {
        $until = $end ?? $this->clock->now();

        return max(0, $until->getTimestamp() - $start->getTimestamp());
    }

    private function siteLabel(int $siteId, string $scope): string
    {
        if ($scope === 'network') {
            return 'network';
        }
        if (function_exists('get_site') && $siteId > 0) {
            $site = get_site($siteId);
            if ($site === null) {
                return 'Site ' . $siteId . ' (deleted)';
            }
        }

        return 'Site ' . $siteId;
    }

    /**
     * @param array<string, scalar|null> $details
     */
    private function audit(Operator $operator, string $category, string $action, string $type, string $id, int $siteId, int $networkId, array $details = []): void
    {
        try {
            $this->audit->record(new AuditEvent(
                $this->clock->now(),
                $category,
                $action,
                $operator->userId,
                $type,
                $id,
                $networkId,
                $siteId,
                $details,
            ));
        } catch (\Throwable) {
        }
    }
}
