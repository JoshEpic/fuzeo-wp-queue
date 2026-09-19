<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Core;

use Fuzeo\Queue\Config\Config;
use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Contracts\ExecutionContextResolver;
use Fuzeo\Queue\Drivers\QueueDriver;
use Fuzeo\Queue\Jobs\EnvelopeFactory;
use Fuzeo\Queue\Jobs\JobRegistry;
use Fuzeo\Queue\Persistence\MigrationRunner;
use Fuzeo\Queue\Runtime\ConsumerRegistry;
use Fuzeo\Queue\Serialization\PayloadSerializer;
use Fuzeo\Queue\Testing\FakeQueue;

final class QueueManager
{
    private Dispatcher $dispatcher;

    private ?FakeQueue $fake = null;

    private readonly ConsumerRegistry $consumers;

    public function __construct(
        private Config $config,
        private readonly JobRegistry $registry,
        private QueueDriver $driver,
        private readonly PayloadSerializer $serializer,
        private readonly ExecutionContextResolver $contextResolver,
        private readonly Clock $clock,
        private readonly MigrationRunner $migrations,
    ) {
        $this->consumers = new ConsumerRegistry();
        $this->dispatcher = $this->makeDispatcher();
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

    public function fake(): FakeQueue
    {
        if ($this->fake === null) {
            $this->fake = new FakeQueue($this->clock);
            $this->dispatcher = $this->makeDispatcher();
        }

        return $this->fake;
    }

    public function useFake(FakeQueue $fake): void
    {
        $this->fake = $fake;
        $this->dispatcher = $this->makeDispatcher();
    }

    public function clearFake(): void
    {
        $this->fake = null;
        $this->dispatcher = $this->makeDispatcher();
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

        return new Dispatcher($factory, $driver, $this->config, $this->fake);
    }
}
