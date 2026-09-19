<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Worker;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Core\QueueManager;
use Fuzeo\Queue\Drivers\Failure;
use Fuzeo\Queue\Drivers\MySql\MySqlDriver;
use Fuzeo\Queue\Drivers\QueueDriver;
use Fuzeo\Queue\Drivers\Reservation;
use Fuzeo\Queue\Drivers\ReserveRequest;
use Fuzeo\Queue\Exceptions\AmbiguousAckException;
use Fuzeo\Queue\Exceptions\DriverException;
use Fuzeo\Queue\Exceptions\JobTimeoutException;
use Fuzeo\Queue\Exceptions\SiteUnavailableException;
use Fuzeo\Queue\Exceptions\UnknownJobException;
use Fuzeo\Queue\Exceptions\UnsupportedEnvelopeException;
use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Persistence\Connection;
use Fuzeo\Queue\Runtime\Hooks;
use Fuzeo\Queue\Support\SystemClock;

final class WorkerLoop
{
    private WorkerStatus $status = WorkerStatus::Running;

    private int $processed = 0;

    private readonly \DateTimeImmutable $startedAt;

    private int $lastHeartbeatAt = 0;

    public function __construct(
        private readonly QueueDriver $driver,
        private readonly JobExecutor $executor,
        private readonly SiteSwitcher $sites,
        private readonly WorkerOptions $options,
        private readonly WorkerIdentity $identity,
        private readonly ?WorkerRepository $workers = null,
        private readonly Clock $clock = new SystemClock(),
        private readonly SignalListener $signals = new SignalListener(),
        private readonly TimeoutGuard $timeouts = new TimeoutGuard(),
        private readonly ?Connection $connection = null,
    ) {
        $this->startedAt = $this->clock->now();
    }

    public static function fromManager(QueueManager $manager, WorkerOptions $options, ?WorkerIdentity $identity = null): self
    {
        $driver = $manager->driver();
        $connection = $driver instanceof MySqlDriver ? $driver->connection() : null;
        $identity ??= WorkerIdentity::generate($manager->clock()->now());
        $workers = $connection !== null ? new WorkerRepository($connection, $manager->clock()) : null;
        $sites = function_exists('switch_to_blog')
            ? new WordPressSiteSwitcher()
            : new NullSiteSwitcher();

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
        foreach ($this->options->queues as $queue) {
            $lease = max($this->options->leaseSeconds, $this->options->timeoutSeconds + 15);
            $reserved = $this->driver->reserve(new ReserveRequest($queue, $this->identity->workerId, $lease));
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
        } catch (JobTimeoutException | SiteUnavailableException | UnknownJobException | UnsupportedEnvelopeException $exception) {
            $this->timeouts->disarm();
            $this->fail($reservation, $exception);
        } catch (AmbiguousAckException $exception) {
            $this->timeouts->disarm();
            throw $exception;
        } catch (\Throwable $exception) {
            $this->timeouts->disarm();
            $this->fail($reservation, $exception);
        }
    }

    private function ack(Reservation $reservation): void
    {
        if ($this->driver instanceof MySqlDriver) {
            $this->driver->acknowledgeOrAmbiguous($reservation);

            return;
        }
        $this->driver->acknowledge($reservation);
    }

    private function fail(Reservation $reservation, \Throwable $exception): void
    {
        Hooks::emit(Hooks::JOB_FAILED, $reservation->envelope, $exception, $this->identity);
        try {
            $this->driver->fail($reservation, new Failure($exception->getMessage(), $exception::class, $exception->getTraceAsString()));
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
            if ($this->connection !== null && !$this->connection->ping()) {
                $this->reconnect();
            }
            $this->workers?->heartbeat($this->identity->workerId, $this->processed, $this->status);
        } catch (DriverException) {
            $this->reconnect();
        }
    }

    private function reconnect(): void
    {
        try {
            $this->connection?->reconnect();
        } catch (DriverException) {
            $this->stop();
        }
    }

    private function idle(): void
    {
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
            // Do not ACK. Lease expiry recovers the job.
        });
    }
}
