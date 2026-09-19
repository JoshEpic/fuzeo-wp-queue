<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Core;

use Fuzeo\Queue\Config\Config;
use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Contracts\ExecutionContextResolver;
use Fuzeo\Queue\Drivers\ProvidesIdempotencyStore;
use Fuzeo\Queue\Drivers\ProvidesScheduleStore;
use Fuzeo\Queue\Drivers\ProvidesUniqueStore;
use Fuzeo\Queue\Drivers\QueueDriver;
use Fuzeo\Queue\Idempotency\Idempotency;
use Fuzeo\Queue\Idempotency\IdempotencyStore;
use Fuzeo\Queue\Idempotency\MemoryIdempotencyStore;
use Fuzeo\Queue\Jobs\EnvelopeFactory;
use Fuzeo\Queue\Jobs\JobRegistry;
use Fuzeo\Queue\Persistence\MigrationRunner;
use Fuzeo\Queue\Runtime\ConsumerRegistry;
use Fuzeo\Queue\Schedule\AlwaysPresentSites;
use Fuzeo\Queue\Schedule\MemoryScheduleStore;
use Fuzeo\Queue\Schedule\ScheduleBook;
use Fuzeo\Queue\Schedule\Scheduler;
use Fuzeo\Queue\Schedule\SitePresence;
use Fuzeo\Queue\Schedule\WordPressSitePresence;
use Fuzeo\Queue\Serialization\PayloadSerializer;
use Fuzeo\Queue\Drivers\ProvidesOrchestration;
use Fuzeo\Queue\Orchestration\MemoryOrchestrationStore;
use Fuzeo\Queue\Orchestration\Orchestrator;
use Fuzeo\Queue\Orchestration\PendingBatch;
use Fuzeo\Queue\Orchestration\PendingChain;
use Fuzeo\Queue\Testing\FakeQueue;
use Fuzeo\Queue\Unique\MemoryUniqueStore;
use Fuzeo\Queue\Unique\UniqueStore;

final class QueueManager
{
    private Dispatcher $dispatcher;

    private ?FakeQueue $fake = null;

    private readonly ConsumerRegistry $consumers;

    private ScheduleBook $schedules;

    private Scheduler $scheduler;

    private Idempotency $idempotency;

    private Orchestrator $orchestrator;

    private readonly SitePresence $sites;

    public function __construct(
        private Config $config,
        private readonly JobRegistry $registry,
        private QueueDriver $driver,
        private readonly PayloadSerializer $serializer,
        private readonly ExecutionContextResolver $contextResolver,
        private readonly Clock $clock,
        private readonly MigrationRunner $migrations,
        ?SitePresence $sites = null,
    ) {
        $this->sites = $sites ?? (function_exists('get_current_blog_id') ? new WordPressSitePresence() : new AlwaysPresentSites());
        $this->consumers = new ConsumerRegistry();
        $this->rebuildControlPlane();
    }

    public function consumers(): ConsumerRegistry
    {
        return $this->consumers;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function jobs(): JobRegistry
    {
        return $this->registry;
    }

    public function driver(): QueueDriver
    {
        return $this->fake?->driver() ?? $this->driver;
    }

    public function serializer(): PayloadSerializer
    {
        return $this->serializer;
    }

    public function context(): ExecutionContextResolver
    {
        return $this->contextResolver;
    }

    public function clock(): Clock
    {
        return $this->clock;
    }

    public function migrations(): MigrationRunner
    {
        return $this->migrations;
    }

    public function dispatcher(): Dispatcher
    {
        return $this->dispatcher;
    }

    public function schedules(): ScheduleBook
    {
        return $this->schedules;
    }

    public function scheduler(): Scheduler
    {
        return $this->scheduler;
    }

    public function idempotency(): Idempotency
    {
        return $this->idempotency;
    }

    public function orchestrator(): Orchestrator
    {
        return $this->orchestrator;
    }

    /**
     * @param list<\Fuzeo\Queue\Jobs\Job> $jobs
     */
    public function chain(array $jobs): PendingChain
    {
        return new PendingChain($this->orchestrator, $jobs);
    }

    /**
     * @param list<\Fuzeo\Queue\Jobs\Job> $jobs
     */
    public function batch(array $jobs): PendingBatch
    {
        return new PendingBatch($this->orchestrator, $jobs);
    }

    public function unique(): UniqueStore
    {
        return $this->uniqueStore();
    }

    public function fake(): FakeQueue
    {
        if ($this->fake === null) {
            $this->fake = new FakeQueue($this->clock);
            $this->rebuildControlPlane();
        }

        /** @var FakeQueue $fake */
        $fake = $this->fake;

        return $fake;
    }

    public function useFake(FakeQueue $fake): void
    {
        $this->fake = $fake;
        $this->rebuildControlPlane();
    }

    public function clearFake(): void
    {
        $this->fake = null;
        $this->rebuildControlPlane();
    }

    public function isFaked(): bool
    {
        return $this->fake !== null;
    }

    private function makeDispatcher(): Dispatcher
    {
        $driver = $this->fake?->driver() ?? $this->driver;
        $factory = new EnvelopeFactory(
            $this->serializer,
            $this->registry,
            $this->contextResolver,
            $this->clock,
            $this->config->defaultMaxAttempts,
            $this->config->defaultTimeoutSeconds,
        );

        return new Dispatcher($factory, $driver, $this->config, $this->fake, $this->clock);
    }

    private function rebuildControlPlane(): void
    {
        $this->dispatcher = $this->makeDispatcher();
        $this->schedules = new ScheduleBook(
            $this->scheduleStore(),
            $this->clock,
            $this->contextResolver,
            $this->registry,
        );
        $this->scheduler = new Scheduler(
            $this->scheduleStore(),
            $this->dispatcher,
            $this->registry,
            $this->uniqueStore(),
            $this->clock,
            $this->sites,
            claimLeaseSeconds: $this->config->scheduleClaimLeaseSeconds,
            maxCatchUp: $this->config->scheduleMaxCatchUp,
            catchUpCutoffDays: $this->config->scheduleCatchUpCutoffDays,
        );
        $this->idempotency = new Idempotency(
            $this->idempotencyStore(),
            $this->contextResolver,
            $this->clock,
            $this->config->idempotencyLeaseSeconds,
            $this->config->idempotencyRetainSeconds,
        );
        $this->orchestrator = new Orchestrator(
            $this->orchestrationStore(),
            $this->dispatcher,
            $this->driver(),
            $this->clock,
            $this->config,
            $this->registry,
            $this->contextResolver,
        );
    }

    private function orchestrationStore(): \Fuzeo\Queue\Orchestration\OrchestrationStore
    {
        $driver = $this->driver();
        if ($driver instanceof ProvidesOrchestration) {
            return $driver->orchestration();
        }

        return new MemoryOrchestrationStore($this->clock);
    }

    private function uniqueStore(): UniqueStore
    {
        $driver = $this->driver();
        if ($driver instanceof ProvidesUniqueStore) {
            return $driver->uniqueStore();
        }

        return new MemoryUniqueStore($this->clock);
    }

    private function scheduleStore(): \Fuzeo\Queue\Schedule\ScheduleStore
    {
        $driver = $this->driver();
        if ($driver instanceof ProvidesScheduleStore) {
            return $driver->scheduleStore();
        }

        return new MemoryScheduleStore($this->clock);
    }

    private function idempotencyStore(): IdempotencyStore
    {
        $driver = $this->driver();
        if ($driver instanceof ProvidesIdempotencyStore) {
            return $driver->idempotencyStore();
        }

        return new MemoryIdempotencyStore($this->clock);
    }
}
