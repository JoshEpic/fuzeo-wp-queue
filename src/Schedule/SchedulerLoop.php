<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Schedule;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Core\QueueManager;
use Fuzeo\Queue\Drivers\MySql\MySqlDriver;
use Fuzeo\Queue\Drivers\QueueDriver;
use Fuzeo\Queue\Drivers\Reconnectable;
use Fuzeo\Queue\Exceptions\ConfigurationException;
use Fuzeo\Queue\Exceptions\DriverException;
use Fuzeo\Queue\Runtime\PackageInfo;
use Fuzeo\Queue\Support\SystemClock;
use Fuzeo\Queue\Support\Ulid;
use Fuzeo\Queue\Worker\SignalListener;
use Fuzeo\Queue\Worker\WorkerIdentity;
use Fuzeo\Queue\Worker\WorkerOptions;

/**
 * Persistent scheduler process. Shares worker lifecycle ideas without executing handlers.
 */
final class SchedulerLoop
{
    private bool $stopping = false;

    private readonly \DateTimeImmutable $startedAt;

    private int $lastHeartbeatAt = 0;

    private int $processed = 0;

    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly ScheduleStore $store,
        private readonly WorkerOptions $options,
        private readonly string $schedulerId,
        private readonly Clock $clock = new SystemClock(),
        private readonly SignalListener $signals = new SignalListener(),
        private readonly ?object $reconnectable = null,
        private readonly ?QueueDriver $driver = null,
    ) {
        $this->startedAt = $this->clock->now();
    }

    public static function fromManager(QueueManager $manager, WorkerOptions $options): self
    {
        $driver = $manager->driver();
        if (!$driver->capabilities()->supports('scheduling')) {
            throw new ConfigurationException('The active driver does not support scheduling.');
        }

        return new self(
            $manager->scheduler(),
            $manager->schedules()->store(),
            $options,
            Ulid::generate(),
            $manager->clock(),
            reconnectable: $driver instanceof Reconnectable ? $driver : null,
            driver: $driver,
        );
    }

    public function schedulerId(): string
    {
        return $this->schedulerId;
    }

    public function processed(): int
    {
        return $this->processed;
    }

    public function run(?int $cycles = null): void
    {
        $this->signals->install();
        $cycle = 0;
        while (!$this->shouldExit()) {
            if ($cycles !== null && $cycle >= $cycles) {
                break;
            }
            $cycle++;
            $this->assertSchemaCompatible();
            $this->heartbeat();
            try {
                $this->processed += $this->scheduler->runDue();
            } catch (DriverException) {
                $this->reconnect();
            }
            if ($this->options->sleepSeconds > 0 && $cycles === null) {
                sleep($this->options->sleepSeconds);
            } elseif ($this->options->sleepSeconds > 0 && $cycles !== null) {
                // Tests pass cycles without sleeping.
            }
        }
    }

    private function heartbeat(): void
    {
        $now = time();
        if ($this->lastHeartbeatAt !== 0 && ($now - $this->lastHeartbeatAt) < $this->options->heartbeatIntervalSeconds) {
            return;
        }
        $this->lastHeartbeatAt = $now;
        $this->store->heartbeat($this->schedulerId, [
            'hostname' => WorkerIdentity::generate($this->clock->now())->hostname,
            'pid' => getmypid() ?: 0,
            'started_at' => $this->startedAt->format(\DateTimeInterface::ATOM),
            'status' => 'running',
            'runtime_version' => PackageInfo::VERSION,
        ]);
    }

    private function assertSchemaCompatible(): void
    {
        if ($this->driver instanceof MySqlDriver) {
            $this->driver->requireCurrentSchema();
        }
    }

    private function reconnect(): void
    {
        if ($this->reconnectable instanceof Reconnectable) {
            try {
                $this->reconnectable->reconnect();
            } catch (DriverException) {
            }
        }
    }

    private function shouldExit(): bool
    {
        if ($this->signals->shouldStop() || $this->stopping) {
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
}
