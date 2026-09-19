<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Core;

use Fuzeo\Queue\Config\Config;
use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Drivers\QueueDriver;
use Fuzeo\Queue\Jobs\DispatchOptions;
use Fuzeo\Queue\Jobs\DispatchResult;
use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\EnvelopeFactory;
use Fuzeo\Queue\Jobs\Job;
use Fuzeo\Queue\Jobs\QueueName;
use Fuzeo\Queue\Support\SystemClock;
use Fuzeo\Queue\Testing\FakeQueue;

final class Dispatcher
{
    public function __construct(
        private readonly EnvelopeFactory $factory,
        private readonly QueueDriver $driver,
        private readonly Config $config,
        private readonly ?FakeQueue $fake = null,
        private readonly Clock $clock = new SystemClock(),
        private readonly ?\Fuzeo\Queue\Metrics\MetricRecorder $metrics = null,
        private readonly ?QueueManager $manager = null,
    ) {
    }

    public function clock(): Clock
    {
        return $this->clock;
    }

    public function dispatch(Job $job, ?DispatchOptions $options = null): DispatchResult
    {
        $this->manager?->operations()->assertDispatchAllowed();
        $options ??= new DispatchOptions(queue: $this->config->defaultQueue);
        if ($options->queue === null) {
            $options = $options->withQueue($this->config->defaultQueue);
        }

        $envelope = $this->factory->make($job, $options);
        $enqueued = $this->driver->enqueue($envelope);
        $this->afterEnqueue($enqueued);

        return new DispatchResult($enqueued->accepted, $enqueued->envelope, $enqueued->duplicateOf);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatchRegistered(string $jobType, array $payload, DispatchOptions $options): DispatchResult
    {
        if ($options->queue === null) {
            $options = $options->withQueue($this->config->defaultQueue);
        }
        $envelope = $this->factory->makeRegistered($jobType, $payload, $options);
        $this->manager?->operations()->assertDispatchAllowed();
        $enqueued = $this->driver->enqueue($envelope);
        $this->afterEnqueue($enqueued);

        return new DispatchResult($enqueued->accepted, $enqueued->envelope, $enqueued->duplicateOf);
    }

    public function on(string $queue): PendingDispatch
    {
        return (new PendingDispatch($this))->on($queue);
    }

    public function later(\DateTimeInterface|int|string $when, Job $job): Envelope
    {
        return (new PendingDispatch($this))->later($when)->dispatch($job);
    }

    public function for(Job $job): PendingDispatch
    {
        unset($job);

        return new PendingDispatch($this, new DispatchOptions(queue: QueueName::DEFAULT));
    }

    private function afterEnqueue(\Fuzeo\Queue\Drivers\EnqueuedJob $enqueued): void
    {
        if (!$enqueued->accepted) {
            return;
        }
        $this->fake?->record($enqueued->envelope);
        $this->metrics?->increment(
            \Fuzeo\Queue\Metrics\MetricName::JOBS_DISPATCHED,
            1,
            \Fuzeo\Queue\Metrics\MetricDimensions::fromEnvelope($enqueued->envelope, $this->config->driver)
        );
    }
}
