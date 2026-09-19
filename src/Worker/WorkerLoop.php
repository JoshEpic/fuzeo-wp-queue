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
        $generation = new RuntimeGeneration($wp);
        $bootGeneration = $generation->current();
        $identity ??= WorkerIdentity::generate($manager->clock()->now(), $bootGeneration);
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
            $generation,
            new MemoryMonitor($baseline->memoryBytes),
            $logger,
            $manager->clock(),
            $bootGeneration,
            $manager->clock()->now(),
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

    public function run(?int $cycles = null): void
    {
        $this->maybeSetProcessTitle();
        $this->signals->install();
        $this->status = WorkerStatus::Starting;
        $this->workers?->register($this->identity, $this->options->queues);
        $this->installFatalGuard();
        Hooks::emit(Hooks::WORKER_STARTED, $this->identity);
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
    }

    public function stop(): void
    {
        $this->signals->requestStop();
        $this->lifecycle->request(RecycleReason::Manual);
        $this->status = WorkerStatus::Stopping;
    }

    private function reserveNext(): ?Reservation
    {
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
            $reserved = $this->driver->reserve(new ReserveRequest($queue, $this->identity->workerId, $lease, $block));
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
        try {
            $this->timeouts->arm($this->jobTimeout($envelope), [$this->timeouts, 'throwTimeout']);
            Hooks::emit(Hooks::JOB_STARTING, $envelope, $this->identity);
            $this->sites->run($envelope->context, function () use ($envelope): void {
                $this->availability?->assert($envelope);
                $this->executor->execute($envelope);
            });
            $this->timeouts->disarm();
            if ($this->cancellation?->isCancellationRequested($envelope->jobId)) {
                $this->settleCancelled($reservation);
                $this->cleanupAfterJob();
                $this->finishJob();

                return;
            }
            $this->ack($reservation);
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
            $this->workers?->heartbeat($this->identity->workerId, $this->processed, $this->status);
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
