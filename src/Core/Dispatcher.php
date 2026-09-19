<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Core;

use Fuzeo\Queue\Config\Config;
use Fuzeo\Queue\Drivers\QueueDriver;
use Fuzeo\Queue\Jobs\DispatchOptions;
use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\EnvelopeFactory;
use Fuzeo\Queue\Jobs\Job;
use Fuzeo\Queue\Jobs\QueueName;
use Fuzeo\Queue\Testing\FakeQueue;

final class Dispatcher
{
    public function __construct(
        private readonly EnvelopeFactory $factory,
        private readonly QueueDriver $driver,
        private readonly Config $config,
        private readonly ?FakeQueue $fake = null,
    ) {
    }

    public function dispatch(Job $job, ?DispatchOptions $options = null): Envelope
    {
        $options ??= new DispatchOptions(queue: $this->config->defaultQueue);
        if ($options->queue === null) {
            $options = $options->withQueue($this->config->defaultQueue);
        }

        $envelope = $this->factory->make($job, $options);
        $this->driver->enqueue($envelope);
        $this->fake?->record($envelope);

        return $envelope;
    }

    public function on(string $queue): PendingDispatch
    {
        return (new PendingDispatch($this))->on($queue);
    }

    public function later(\DateTimeInterface|int $when, Job $job): Envelope
    {
        return (new PendingDispatch($this))->later($when)->dispatch($job);
    }

    public function for(Job $job): PendingDispatch
    {
        return new PendingDispatch($this, new DispatchOptions(queue: QueueName::DEFAULT));
    }
}
