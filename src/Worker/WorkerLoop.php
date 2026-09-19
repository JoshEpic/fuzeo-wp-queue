<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Worker;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Core\QueueManager;
use Fuzeo\Queue\Drivers\Failure;
use Fuzeo\Queue\Drivers\FailureStore;
use Fuzeo\Queue\Drivers\ProvidesWorkerStore;
use Fuzeo\Queue\Drivers\QueueDriver;
use Fuzeo\Queue\Drivers\Reconnectable;
use Fuzeo\Queue\Drivers\Redis\RedisDriver;
use Fuzeo\Queue\Drivers\ReliableAcknowledger;
use Fuzeo\Queue\Drivers\Reservation;
use Fuzeo\Queue\Drivers\ReserveRequest;
use Fuzeo\Queue\Drivers\CancelsJobs;
use Fuzeo\Queue\Exceptions\AmbiguousAckException;
use Fuzeo\Queue\Exceptions\JobCancelledException;
use Fuzeo\Queue\Orchestration\Orchestrator;
use Fuzeo\Queue\Exceptions\ConfigurationException;
use Fuzeo\Queue\Exceptions\DriverException;
use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Persistence\Connection;
use Fuzeo\Queue\Retry\AttemptRecord;
use Fuzeo\Queue\Retry\FailureClassifier;
use Fuzeo\Queue\Retry\RandomSource;
use Fuzeo\Queue\Retry\SystemRandom;
use Fuzeo\Queue\Deployment\DeploymentGeneration;
use Fuzeo\Queue\Deployment\DeploymentWatch;
use Fuzeo\Queue\Deployment\ProcessExitCode;
use Fuzeo\Queue\Deployment\ReadinessReason;
use Fuzeo\Queue\Runtime\ErrorLogLogger;
use Fuzeo\Queue\Runtime\Hooks;
use Fuzeo\Queue\Runtime\MemoryMonitor;
use Fuzeo\Queue\Runtime\NativeWordPressRuntime;
use Fuzeo\Queue\Runtime\NullLogger;
use Fuzeo\Queue\Runtime\ProcessLifecycle;
use Fuzeo\Queue\Runtime\RecycleReason;
use Fuzeo\Queue\Runtime\RuntimeBaseline;
use Fuzeo\Queue\Runtime\RuntimeGeneration;
use Fuzeo\Queue\Runtime\RuntimeLogger;
use Fuzeo\Queue\Runtime\RuntimeResetter;
use Fuzeo\Queue\Runtime\WordPressRuntime;
use Fuzeo\Queue\Support\SecretRedactor;
use Fuzeo\Queue\Support\SystemClock;
use Fuzeo\Queue\Support\TraceSanitizer;

final class WorkerLoop
{
    private WorkerStatus $status = WorkerStatus::Starting;

    private int $processed = 0;

    private readonly \DateTimeImmutable $startedAt;

    private int $lastHeartbeatAt = 0;

    private int $reconnectFailures = 0;

    private ?Reservation $activeReservation = null;

    private readonly ProcessLifecycle $lifecycle;

    private int $startupExitCode = ProcessExitCode::OK;

    public function __construct(
        private readonly QueueDriver $driver,
        private readonly JobExecutor $executor,
        private readonly SiteSwitcher $sites,
        private readonly WorkerOptions $options,
        private readonly WorkerIdentity $identity,
        private readonly ?WorkerStore $workers = null,
        private readonly Clock $clock = new SystemClock(),
        private readonly SignalListener $signals = new SignalListener(),
        private readonly TimeoutGuard $timeouts = new TimeoutGuard(),
        private readonly ?Connection $connection = null,
        private readonly FailureClassifier $classifier = new FailureClassifier(),
        private readonly RandomSource $random = new SystemRandom(),
        private readonly SecretRedactor $redactor = new SecretRedactor(),
        private readonly ?Orchestrator $orchestrator = null,
        private readonly ?CancelsJobs $cancellation = null,
        private readonly ?RuntimeResetter $resetter = null,
        ?ProcessLifecycle $lifecycle = null,
        private readonly RuntimeLogger $logger = new NullLogger(),
        private readonly ?HandlerAvailability $availability = null,
        private readonly ?\Fuzeo\Queue\Metrics\MetricRecorder $metrics = null,
        private readonly ?\Fuzeo\Queue\Operations\Operations $operations = null,
    ) {
        $this->startedAt = $this->clock->now();
        $this->lifecycle = $lifecycle ?? new ProcessLifecycle(
            $options,
            new RuntimeGeneration(new NativeWordPressRuntime()),
            new MemoryMonitor(memory_get_usage(true)),
            $this->logger,
            $this->clock,
            $identity->runtimeGeneration !== '' ? $identity->runtimeGeneration : null,
            $this->startedAt,
        );
    }

    public static function fromManager(QueueManager $manager, WorkerOptions $options, ?WorkerIdentity $identity = null): self
    {
        $driver = $manager->driver();
        $wp = new NativeWordPressRuntime();
        $runtimeGeneration = new RuntimeGeneration($wp);
        $deploymentGeneration = new DeploymentGeneration($runtimeGeneration, $manager->config()->deploymentId);
        $watch = DeploymentWatch::boot($manager->deployment(), $deploymentGeneration, $manager->clock());
        $bootGeneration = $deploymentGeneration->current();
        $identity ??= WorkerIdentity::generate(
            $manager->clock()->now(),
            $runtimeGeneration->current(),
            $bootGeneration,
            $watch->bootRestartGeneration(),
            $options->processType,
        );
        $workers = $driver instanceof ProvidesWorkerStore ? $driver->workerStore() : null;
        $connection = $driver instanceof \Fuzeo\Queue\Drivers\MySql\MySqlDriver ? $driver->connection() : null;
        $sites = function_exists('switch_to_blog')
            ? new WordPressSiteSwitcher($wp)
            : new NullSiteSwitcher();
        $policy = \Fuzeo\Queue\Concurrency\AdmissionPolicy::fromConfig($manager->config());
        if ($policy->concurrencyByQueue !== [] && !$driver->capabilities()->supports('queue_concurrency')) {
            throw new ConfigurationException(
                'Queue concurrency limits are configured but the active driver does not support queue_concurrency.'
            );
        }
        if ($policy->globalRateLimits !== [] && !$driver->capabilities()->supports('atomic_rate_limits')) {
            throw new ConfigurationException(
                'Rate limits are configured but the active driver does not support atomic_rate_limits.'
            );
        }
        $logger = defined('WP_DEBUG') && WP_DEBUG ? new ErrorLogLogger() : new NullLogger();
        $baseline = RuntimeBaseline::capture($wp, $bootGeneration);
        $redis = $driver instanceof RedisDriver ? $driver->redis() : null;
        $resetter = $options->runtimeReset
            ? new RuntimeResetter($baseline, $wp, $logger, $connection, $redis)
            : null;
        $lifecycle = new ProcessLifecycle(
            $options,
            $deploymentGeneration,
            new MemoryMonitor($baseline->memoryBytes),
            $logger,
            $manager->clock(),
            $bootGeneration,
            $manager->clock()->now(),
            $watch,
        );

        return new self(
            $driver,
            new JobExecutor($manager->jobs(), $driver instanceof CancelsJobs ? $driver : null),
            $sites,
            $options,
            $identity,
            $workers,
            $manager->clock(),
            connection: $connection,
            orchestrator: $manager->orchestrator(),
            cancellation: $driver instanceof CancelsJobs ? $driver : null,
            resetter: $resetter,
            lifecycle: $lifecycle,
            logger: $logger,
            availability: new HandlerAvailability($manager->jobs(), $wp),
            metrics: $manager->metrics(),
            operations: $manager->operations(),
        );
    }

    public function identity(): WorkerIdentity
    {
        return $this->identity;
    }

    public function processed(): int
    {
        return $this->processed;
    }

    public function recycleReason(): RecycleReason
    {
        return $this->lifecycle->reason();
    }

    public function status(): WorkerStatus
    {
        return $this->status;
    }

    public function exitCode(): int
    {
        if ($this->startupExitCode !== ProcessExitCode::OK) {
            return $this->startupExitCode;
        }

        return ProcessExitCode::fromReason($this->lifecycle->reason());
    }

    public function run(?int $cycles = null): void
    {
        $this->maybeSetProcessTitle();
        $this->signals->install();
        $this->status = WorkerStatus::Starting;
        if (!$this->assertStartup()) {
            $this->status = WorkerStatus::Stopped;
            Hooks::emit(Hooks::WORKER_STOPPED, $this->identity, $this->lifecycle->reason()->value);

            return;
        }
        $this->workers?->register($this->identity, $this->options->queues);
        if ($this->options->processType === \Fuzeo\Queue\Execution\ProcessType::CronCli) {
            try {
                $this->operations?->recordCronWorker();
            } catch (\Throwable) {
            }
        }
        $this->installFatalGuard();
        Hooks::emit(Hooks::WORKER_STARTED, $this->identity);
        $this->operations?->recordEvent('worker.started', 'worker', $this->identity->workerId);
        $this->status = WorkerStatus::Idle;

        $cycle = 0;
        while (!$this->lifecycle->shouldExit($this->processed, $this->signals->shouldStop() || $this->status === WorkerStatus::Stopping)) {
            if ($cycles !== null && $cycle >= $cycles) {
                $this->lifecycle->request(RecycleReason::CyclesComplete);
                break;
            }
            $cycle++;
            $this->heartbeat();
            if (!$this->connectionsHealthy()) {
                continue;
            }
            $reserved = $this->reserveNext();
            if ($reserved === null) {
                if ($this->options->sleepSeconds === 0 && !$this->driver->capabilities()->supports('blocking_reserve')) {
                    break;
                }
                $this->status = WorkerStatus::Idle;
                $this->idle();
                $this->orchestrator?->reconcile(10);
                $this->lifecycle->refreshGeneration();
                continue;
            }
            $this->process($reserved);
            $this->processed++;
            $this->lifecycle->afterJob();
        }

        $this->status = WorkerStatus::Stopping;
        Hooks::emit(Hooks::WORKER_STOPPING, $this->identity, $this->lifecycle->reason()->value);
        $this->workers?->stop($this->identity->workerId);
        $this->status = WorkerStatus::Stopped;
        Hooks::emit(Hooks::WORKER_STOPPED, $this->identity, $this->lifecycle->reason()->value);
        $this->operations?->recordEvent('worker.recycled', 'worker', $this->identity->workerId, [
            'reason' => $this->lifecycle->reason()->value,
        ]);
    }

    public function stop(): void
    {
        $this->signals->requestStop();
        $this->lifecycle->request(RecycleReason::Manual);
        $this->status = WorkerStatus::Stopping;
    }

    private function reserveNext(): ?Reservation
    {
        if (!$this->lifecycle->mayReserve()) {
            if ($this->status !== WorkerStatus::Stopping && $this->status !== WorkerStatus::Stopped) {
                $this->status = WorkerStatus::Draining;
            }

            return null;
        }
        if ($this->operations !== null && !$this->operations->compatibility()->canReserve) {
            $this->status = WorkerStatus::Draining;

            return null;
        }
        $queues = $this->options->queues;
        $lastIndex = array_key_last($queues);
        foreach ($queues as $index => $queue) {
            $lease = max($this->options->leaseSeconds, $this->options->timeoutSeconds + 15);
            $block = 0;
            if (
                $index === $lastIndex
                && $this->driver->capabilities()->supports('blocking_reserve')
                && $this->options->sleepSeconds > 0
            ) {
                $block = $this->options->sleepSeconds;
            }
            $reserved = $this->driver->reserve(new ReserveRequest(
                $queue,
                $this->identity->workerId,
                $lease,
                $block,
                $this->options->executionClass,
                $this->options->maxTimeoutSeconds,
            ));
            if ($reserved !== null) {
                return $reserved;
            }
        }

        return null;
    }

    private function process(Reservation $reservation): void
    {
        $this->status = WorkerStatus::Working;
        $this->activeReservation = $reservation;
        $envelope = $reservation->envelope;
        if ($this->cancellation?->isCancellationRequested($envelope->jobId)) {
            $this->settleCancelled($reservation);
            $this->cleanupAfterJob();
            $this->finishJob();

            return;
        }
        $this->resetter?->prepare();
        Hooks::emit(Hooks::JOB_PREPARING, $envelope, $this->identity);
        Hooks::emit(Hooks::BEFORE_JOB, $envelope, $this->identity);
        $waitMs = \Fuzeo\Queue\Metrics\Timing::waitMs($reservation->reservedAt, $envelope->availableAt);
        $started = hrtime(true);
        try {
            $this->timeouts->arm($this->jobTimeout($envelope), [$this->timeouts, 'throwTimeout']);
            Hooks::emit(Hooks::JOB_STARTING, $envelope, $this->identity);
            $this->sites->run($envelope->context, function () use ($envelope): void {
                $this->availability?->assert($envelope);
                $this->executor->execute($envelope);
            });
            $this->timeouts->disarm();
            $runtimeMs = (hrtime(true) - $started) / 1e6;
            if ($this->cancellation?->isCancellationRequested($envelope->jobId)) {
                $this->settleCancelled($reservation);
                $this->cleanupAfterJob();
                $this->finishJob();

                return;
            }
            $this->ack($reservation);
            $this->recordSuccess($reservation, $waitMs, $runtimeMs);
            $this->orchestrator?->onCompleted($envelope->withState(\Fuzeo\Queue\Jobs\JobState::Completed));
            Hooks::emit(Hooks::JOB_COMPLETED, $envelope, $this->identity);
            Hooks::emit(Hooks::AFTER_JOB, $envelope, $this->identity);
        } catch (JobCancelledException $exception) {
            unset($exception);
            $this->timeouts->disarm();
            $this->settleCancelled($reservation);
        } catch (AmbiguousAckException $exception) {
            $this->timeouts->disarm();
            $this->cleanupAfterJob();
            throw $exception;
        } catch (\Throwable $exception) {
            $this->timeouts->disarm();
            $this->handleFailure($reservation, $exception);
        }
        $this->cleanupAfterJob();
        $this->finishJob();
    }

    private function cleanupAfterJob(): void
    {
        $this->timeouts->disarm();
        $this->signals->install();
        if ($this->resetter === null) {
            return;
        }
        $result = $this->resetter->reset();
        Hooks::emit(Hooks::RUNTIME_RESET, $result);
        if (!$result->ok && $this->options->recycleOnContextError && $result->recycle !== null) {
            $this->status = WorkerStatus::Unhealthy;
            $this->lifecycle->request($result->recycle);
        }
    }

    private function finishJob(): void
    {
        $this->activeReservation = null;
        Hooks::emit(Hooks::JOB_FINISHED, $this->identity);
        $this->status = WorkerStatus::Idle;
    }

    private function ack(Reservation $reservation): void
    {
        if ($this->driver instanceof ReliableAcknowledger) {
            $this->driver->acknowledgeOrAmbiguous($reservation);

            return;
        }
        $this->driver->acknowledge($reservation);
    }

    private function handleFailure(Reservation $reservation, \Throwable $exception): void
    {
        Hooks::emit(Hooks::JOB_FAILED, $reservation->envelope, $exception, $this->identity);
        $decision = $this->classifier->decide(
            $exception,
            $reservation->envelope,
            $reservation->envelope->retryPolicy(),
            $this->clock,
            $this->random,
        );
        $record = AttemptRecord::fromFailure(
            $reservation->envelope,
            $exception,
            $decision,
            $this->redactor->redactMessage($exception->getMessage()),
            TraceSanitizer::sanitize($exception),
            $this->clock->now(),
            $reservation->workerId,
            $reservation->token->value,
        );

        if ($this->driver instanceof FailureStore) {
            try {
                $this->driver->settleOutcome($reservation, $record, $decision->nextState, $decision->availableAt);
                $this->recordFailureMetrics($reservation, $decision);
                if ($decision->nextState === \Fuzeo\Queue\Jobs\JobState::Dead) {
                    $this->orchestrator?->onDead($reservation->envelope->withState(\Fuzeo\Queue\Jobs\JobState::Dead));
                }
            } catch (DriverException) {
                // Lease recovery remains the source of truth if settlement cannot persist.
            }

            return;
        }

        try {
            $this->driver->fail(
                $reservation,
                new Failure($record->sanitizedMessage, $exception::class, $record->sanitizedTrace)
            );
            $this->orchestrator?->onDead($reservation->envelope->withState(\Fuzeo\Queue\Jobs\JobState::Dead));
        } catch (DriverException) {
            // Lease recovery remains the source of truth if fail cannot persist.
        }
    }

    private function settleCancelled(Reservation $reservation): void
    {
        try {
            $this->cancellation?->settleCancelled($reservation);
        } catch (DriverException) {
        }
        try {
            $this->orchestrator?->onCancelled($reservation->envelope->withState(\Fuzeo\Queue\Jobs\JobState::Cancelled));
        } catch (\Throwable) {
        }
        Hooks::emit(Hooks::JOB_CANCELLED, $reservation->envelope, $this->identity);
        $dims = \Fuzeo\Queue\Metrics\MetricDimensions::fromEnvelope($reservation->envelope, '', 'cancelled');
        $this->metrics?->increment(\Fuzeo\Queue\Metrics\MetricName::JOBS_CANCELLED, 1, $dims);
    }

    private function recordSuccess(Reservation $reservation, float $waitMs, float $runtimeMs): void
    {
        $envelope = $reservation->envelope;
        $dims = \Fuzeo\Queue\Metrics\MetricDimensions::fromEnvelope($envelope, '', 'completed');
        $this->metrics?->increment(\Fuzeo\Queue\Metrics\MetricName::JOBS_COMPLETED, 1, $dims);
        $this->metrics?->observe(\Fuzeo\Queue\Metrics\MetricName::WAIT_MS, $waitMs, $dims);
        $this->metrics?->observe(\Fuzeo\Queue\Metrics\MetricName::RUNTIME_MS, $runtimeMs, $dims);
        $successMs = max(0.0, ($this->clock->now()->getTimestamp() - $envelope->createdAt->getTimestamp()) * 1000.0);
        $this->metrics?->observe(\Fuzeo\Queue\Metrics\MetricName::SUCCESS_MS, $successMs, $dims);
        if ($envelope->attempt > 1) {
            $this->metrics?->increment(\Fuzeo\Queue\Metrics\MetricName::RETRY_SUCCESS, 1, $dims);
        }
    }

    private function recordFailureMetrics(Reservation $reservation, \Fuzeo\Queue\Retry\RetryDecision $decision): void
    {
        $envelope = $reservation->envelope;
        $dims = \Fuzeo\Queue\Metrics\MetricDimensions::fromEnvelope($envelope, '', $decision->willRetry ? 'retry' : 'dead');
        $this->metrics?->increment(\Fuzeo\Queue\Metrics\MetricName::JOBS_FAILED, 1, $dims);
        if ($decision->willRetry) {
            $this->metrics?->increment(\Fuzeo\Queue\Metrics\MetricName::JOBS_RETRIED, 1, $dims);
            $this->metrics?->increment(\Fuzeo\Queue\Metrics\MetricName::RETRY_ATTEMPTS, 1, $dims);
            if ($decision->availableAt !== null) {
                $delay = max(0.0, ($decision->availableAt->getTimestamp() - $this->clock->now()->getTimestamp()) * 1000.0);
                $this->metrics?->observe(\Fuzeo\Queue\Metrics\MetricName::RETRY_DELAY_MS, $delay, $dims);
            }
        } else {
            $this->metrics?->increment(\Fuzeo\Queue\Metrics\MetricName::RETRY_EXHAUSTED, 1, $dims);
            $this->metrics?->increment(\Fuzeo\Queue\Metrics\MetricName::JOBS_DEAD, 1, $dims);
        }
    }

    private function heartbeat(): void
    {
        $now = time();
        if ($this->lastHeartbeatAt !== 0 && ($now - $this->lastHeartbeatAt) < $this->options->heartbeatIntervalSeconds) {
            return;
        }
        $this->lastHeartbeatAt = $now;
        try {
            $this->connectionsHealthy();
            $this->workers?->heartbeat(
                $this->identity->workerId,
                $this->processed,
                $this->status,
                $this->lifecycle->reason()->value,
                $this->lifecycle->bootGeneration(),
            );
        } catch (DriverException) {
            $this->backoffAfterReconnectFailure();
        }
    }

    private function connectionsHealthy(): bool
    {
        try {
            if ($this->driver instanceof Reconnectable && !$this->driver->ping()) {
                $this->reconnect();
                $this->logger->log('runtime.db_reconnected', ['driver' => 'queue']);
            }
            if ($this->connection !== null && !$this->connection->ping()) {
                $this->reconnect();
                $this->logger->log('runtime.db_reconnected', ['driver' => 'mysql']);
            }
            $this->reconnectFailures = 0;

            return true;
        } catch (DriverException) {
            $this->backoffAfterReconnectFailure();

            return false;
        }
    }

    private function reconnect(): void
    {
        try {
            if ($this->driver instanceof Reconnectable) {
                $this->driver->reconnect();
            }
            $this->connection?->reconnect();
            $this->reconnectFailures = 0;
        } catch (DriverException) {
            $this->backoffAfterReconnectFailure();
        }
    }

    private function backoffAfterReconnectFailure(): void
    {
        $this->reconnectFailures = min(6, $this->reconnectFailures + 1);
        $base = min(30, 2 ** $this->reconnectFailures);
        $jitter = $base > 1 ? random_int(0, (int) max(1, (int) ($base * 0.25))) : 0;
        sleep($base + $jitter);
        if ($this->reconnectFailures >= 6) {
            $this->status = WorkerStatus::Unhealthy;
            $this->lifecycle->request(RecycleReason::HealthFailure);
            $this->stop();
        }
    }

    private function idle(): void
    {
        if ($this->driver->capabilities()->supports('blocking_reserve')) {
            return;
        }
        if ($this->options->sleepSeconds > 0) {
            sleep($this->options->sleepSeconds);
        }
    }

    private function assertStartup(): bool
    {
        if ($this->operations === null) {
            return true;
        }
        $compat = $this->operations->compatibility();
        if (!$compat->canBoot) {
            $reason = match ($compat->primaryReason) {
                ReadinessReason::SchemaMismatch->value, ReadinessReason::MigrationInProgress->value => RecycleReason::SchemaMismatch,
                ReadinessReason::DriverUnavailable->value => RecycleReason::HealthFailure,
                default => RecycleReason::RuntimeIncompatible,
            };
            $this->lifecycle->request($reason);
            $this->startupExitCode = ProcessExitCode::fromReadinessReason($compat->primaryReason);

            return false;
        }

        return true;
    }

    private function jobTimeout(Envelope $envelope): int
    {
        return max($envelope->timeoutSeconds, 1);
    }

    private function maybeSetProcessTitle(): void
    {
        $title = $this->options->processTitle !== ''
            ? $this->options->processTitle
            : 'fuzeo-queue worker ' . implode(',', $this->options->queues);
        if (function_exists('cli_set_process_title')) {
            @cli_set_process_title($title);
        }
    }

    private function installFatalGuard(): void
    {
        register_shutdown_function(function (): void {
            $error = error_get_last();
            if ($error === null) {
                return;
            }
            $fatals = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
            if (!in_array($error['type'], $fatals, true)) {
                return;
            }
            if ($this->activeReservation !== null) {
                $this->logger->log('worker.fatal_with_reservation', [
                    'job_id' => $this->activeReservation->envelope->jobId,
                    'type' => (string) $error['type'],
                ]);
            }
            // Do not ACK. Lease expiry recovers the job and consumes the reserved attempt.
        });
    }
}
