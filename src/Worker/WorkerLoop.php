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
use Fuzeo\Queue\Drivers\ReliableAcknowledger;
use Fuzeo\Queue\Drivers\Reservation;
use Fuzeo\Queue\Drivers\ReserveRequest;
use Fuzeo\Queue\Exceptions\AmbiguousAckException;
use Fuzeo\Queue\Exceptions\ConfigurationException;
use Fuzeo\Queue\Exceptions\DriverException;
use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Persistence\Connection;
use Fuzeo\Queue\Retry\AttemptRecord;
use Fuzeo\Queue\Retry\FailureClassifier;
use Fuzeo\Queue\Retry\RandomSource;
use Fuzeo\Queue\Retry\SystemRandom;
use Fuzeo\Queue\Runtime\Hooks;
use Fuzeo\Queue\Support\SecretRedactor;
use Fuzeo\Queue\Support\SystemClock;
use Fuzeo\Queue\Support\TraceSanitizer;

final class WorkerLoop
{
    private WorkerStatus $status = WorkerStatus::Running;

    private int $processed = 0;

    private readonly \DateTimeImmutable $startedAt;

    private int $lastHeartbeatAt = 0;

    private int $reconnectFailures = 0;

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
    ) {
        $this->startedAt = $this->clock->now();
    }

    public static function fromManager(QueueManager $manager, WorkerOptions $options, ?WorkerIdentity $identity = null): self
    {
        $driver = $manager->driver();
        $identity ??= WorkerIdentity::generate($manager->clock()->now());
        $workers = $driver instanceof ProvidesWorkerStore ? $driver->workerStore() : null;
        $connection = $driver instanceof \Fuzeo\Queue\Drivers\MySql\MySqlDriver ? $driver->connection() : null;
        $sites = function_exists('switch_to_blog')
            ? new WordPressSiteSwitcher()
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

        return new self(
            $driver,
            new JobExecutor($manager->jobs()),
            $sites,
            $options,
            $identity,
            $workers,
            $manager->clock(),
            connection: $connection,
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

    public function run(?int $cycles = null): void
    {
        $this->signals->install();
        $this->workers?->register($this->identity, $this->options->queues);
        $this->installFatalGuard();

        $cycle = 0;
        while (!$this->shouldExit()) {
            if ($cycles !== null && $cycle >= $cycles) {
                break;
            }
            $cycle++;
            $this->heartbeat();
            $reserved = $this->reserveNext();
            if ($reserved === null) {
                $this->idle();
                continue;
            }
            $this->process($reserved);
            $this->processed++;
        }

        $this->status = WorkerStatus::Stopping;
        Hooks::emit(Hooks::WORKER_STOPPING, $this->identity);
        $this->workers?->stop($this->identity->workerId);
        $this->status = WorkerStatus::Stopped;
    }

    public function stop(): void
    {
        $this->signals->requestStop();
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
        $envelope = $reservation->envelope;
        Hooks::emit(Hooks::BEFORE_JOB, $envelope, $this->identity);
        try {
            $this->timeouts->arm($this->jobTimeout($envelope), [$this->timeouts, 'throwTimeout']);
            $this->sites->run($envelope->context, function () use ($envelope): void {
                $this->executor->execute($envelope);
            });
            $this->timeouts->disarm();
            $this->ack($reservation);
            Hooks::emit(Hooks::AFTER_JOB, $envelope, $this->identity);
        } catch (AmbiguousAckException $exception) {
            $this->timeouts->disarm();
            throw $exception;
        } catch (\Throwable $exception) {
            $this->timeouts->disarm();
            $this->handleFailure($reservation, $exception);
        }
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
        } catch (DriverException) {
            // Lease recovery remains the source of truth if fail cannot persist.
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
            if ($this->driver instanceof Reconnectable && !$this->driver->ping()) {
                $this->reconnect();
            }
            if ($this->connection !== null && !$this->connection->ping()) {
                $this->reconnect();
            }
            $this->workers?->heartbeat($this->identity->workerId, $this->processed, $this->status);
        } catch (DriverException) {
            $this->backoffAfterReconnectFailure();
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

    private function shouldExit(): bool
    {
        if ($this->signals->shouldStop() || $this->status === WorkerStatus::Stopping) {
            return true;
        }
        if ($this->options->maxJobs > 0 && $this->processed >= $this->options->maxJobs) {
            return true;
        }
        if ($this->options->maxRuntimeSeconds > 0) {
            $elapsed = $this->clock->now()->getTimestamp() - $this->startedAt->getTimestamp();
            if ($elapsed >= $this->options->maxRuntimeSeconds) {
                return true;
            }
        }
        if (memory_get_usage(true) >= $this->options->memoryBytes) {
            return true;
        }

        return false;
    }

    private function jobTimeout(Envelope $envelope): int
    {
        return max($envelope->timeoutSeconds, 1);
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
            // Do not ACK. Lease expiry recovers the job and consumes the reserved attempt.
        });
    }
}
