<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Schedule;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Core\QueueManager;
use Fuzeo\Queue\Drivers\MySql\MySqlDriver;
use Fuzeo\Queue\Drivers\QueueDriver;
use Fuzeo\Queue\Drivers\Reconnectable;
use Fuzeo\Queue\Drivers\Redis\RedisDriver;
use Fuzeo\Queue\Exceptions\ConfigurationException;
use Fuzeo\Queue\Exceptions\DriverException;
use Fuzeo\Queue\Runtime\ErrorLogLogger;
use Fuzeo\Queue\Runtime\Hooks;
use Fuzeo\Queue\Runtime\MemoryMonitor;
use Fuzeo\Queue\Runtime\NativeWordPressRuntime;
use Fuzeo\Queue\Runtime\NullLogger;
use Fuzeo\Queue\Runtime\PackageInfo;
use Fuzeo\Queue\Runtime\ProcessLifecycle;
use Fuzeo\Queue\Runtime\RecycleReason;
use Fuzeo\Queue\Runtime\RuntimeBaseline;
use Fuzeo\Queue\Runtime\RuntimeGeneration;
use Fuzeo\Queue\Runtime\RuntimeLogger;
use Fuzeo\Queue\Runtime\RuntimeResetter;
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
    private readonly \DateTimeImmutable $startedAt;

    private int $lastHeartbeatAt = 0;

    private int $processed = 0;

    private readonly ProcessLifecycle $lifecycle;

    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly ScheduleStore $store,
        private readonly WorkerOptions $options,
        private readonly string $schedulerId,
        private readonly Clock $clock = new SystemClock(),
        private readonly SignalListener $signals = new SignalListener(),
        private readonly ?object $reconnectable = null,
        private readonly ?QueueDriver $driver = null,
        ?ProcessLifecycle $lifecycle = null,
        private readonly ?RuntimeResetter $resetter = null,
        private readonly RuntimeLogger $logger = new NullLogger(),
    ) {
        $this->startedAt = $this->clock->now();
        $this->lifecycle = $lifecycle ?? new ProcessLifecycle(
            $options,
            new RuntimeGeneration(new NativeWordPressRuntime()),
            new MemoryMonitor(memory_get_usage(true)),
            $this->logger,
            $this->clock,
            null,
            $this->startedAt,
        );
    }

    public static function fromManager(QueueManager $manager, WorkerOptions $options): self
    {
        $driver = $manager->driver();
        if (!$driver->capabilities()->supports('scheduling')) {
            throw new ConfigurationException('The active driver does not support scheduling.');
        }
        $wp = new NativeWordPressRuntime();
        $generation = new RuntimeGeneration($wp);
        $boot = $generation->current();
        $logger = defined('WP_DEBUG') && WP_DEBUG ? new ErrorLogLogger() : new NullLogger();
        $connection = $driver instanceof MySqlDriver ? $driver->connection() : null;
        $redis = $driver instanceof RedisDriver ? $driver->redis() : null;
        $resetter = $options->runtimeReset
            ? new RuntimeResetter(RuntimeBaseline::capture($wp, $boot), $wp, $logger, $connection, $redis)
            : null;
        $lifecycle = new ProcessLifecycle(
            $options,
            $generation,
            new MemoryMonitor(memory_get_usage(true)),
            $logger,
            $manager->clock(),
            $boot,
            $manager->clock()->now(),
        );

        return new self(
            $manager->scheduler(),
            $manager->schedules()->store(),
            $options,
            Ulid::generate(),
            $manager->clock(),
            reconnectable: $driver instanceof Reconnectable ? $driver : null,
            driver: $driver,
            lifecycle: $lifecycle,
            resetter: $resetter,
            logger: $logger,
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

    public function recycleReason(): RecycleReason
    {
        return $this->lifecycle->reason();
    }

    public function run(?int $cycles = null): void
    {
        $this->signals->install();
        Hooks::emit(Hooks::WORKER_STARTED, $this->schedulerId);
        $cycle = 0;
        while (!$this->lifecycle->shouldExit($this->processed, $this->signals->shouldStop())) {
            if ($cycles !== null && $cycle >= $cycles) {
                $this->lifecycle->request(RecycleReason::CyclesComplete);
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
            $this->resetter?->reset();
            $this->lifecycle->afterJob();
            if ($this->options->sleepSeconds > 0 && $cycles === null) {
                sleep($this->options->sleepSeconds);
            }
        }
        Hooks::emit(Hooks::WORKER_STOPPING, $this->schedulerId, $this->lifecycle->reason()->value);
        Hooks::emit(Hooks::WORKER_STOPPED, $this->schedulerId, $this->lifecycle->reason()->value);
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
            'status' => $this->lifecycle->reason() === RecycleReason::None ? 'running' : 'stopping',
            'runtime_version' => PackageInfo::VERSION,
            'runtime_generation' => $this->lifecycle->bootGeneration(),
            'recycle_reason' => $this->lifecycle->reason()->value,
            'memory_bytes' => memory_get_usage(true),
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
                $this->logger->log('runtime.db_reconnected', ['role' => 'scheduler']);
            } catch (DriverException) {
                $this->lifecycle->request(RecycleReason::HealthFailure);
            }
        }
    }
}
