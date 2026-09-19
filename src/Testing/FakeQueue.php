<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Testing;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Drivers\Memory\MemoryDriver;
use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\Job;
use Fuzeo\Queue\Jobs\JobType;
use Fuzeo\Queue\Support\SystemClock;

final class FakeQueue
{
    private MemoryDriver $driver;

    /** @var list<Envelope> */
    private array $dispatched = [];

    public function __construct(Clock $clock = new SystemClock())
    {
        $this->driver = new MemoryDriver($clock);
    }

    public function driver(): MemoryDriver
    {
        return $this->driver;
    }

    public function record(Envelope $envelope): void
    {
        $this->dispatched[] = $envelope;
    }

    /**
     * @return list<Envelope>
     */
    public function dispatched(?string $jobTypeOrClass = null): array
    {
        if ($jobTypeOrClass === null) {
            return $this->dispatched;
        }

        $type = $this->resolveType($jobTypeOrClass);

        return array_values(array_filter(
            $this->dispatched,
            static fn (Envelope $envelope): bool => $envelope->jobType === $type
        ));
    }

    public function assertDispatched(string $jobTypeOrClass, ?callable $callback = null): void
    {
        $matches = $this->dispatched($jobTypeOrClass);
        if ($callback !== null) {
            $matches = array_values(array_filter($matches, $callback));
        }

        if ($matches === []) {
            throw new \Fuzeo\Queue\Exceptions\QueueException(
                'Job ' . $jobTypeOrClass . ' was not dispatched.'
            );
        }
    }

    public function assertNotDispatched(string $jobTypeOrClass): void
    {
        if ($this->dispatched($jobTypeOrClass) !== []) {
            throw new \Fuzeo\Queue\Exceptions\QueueException(
                'Job ' . $jobTypeOrClass . ' was dispatched unexpectedly.'
            );
        }
    }

    public function assertDispatchedTimes(string $jobTypeOrClass, int $times): void
    {
        $count = count($this->dispatched($jobTypeOrClass));
        if ($count !== $times) {
            throw new \Fuzeo\Queue\Exceptions\QueueException(
                'Job ' . $jobTypeOrClass . ' was dispatched ' . $count . ' times; expected ' . $times . '.'
            );
        }
    }

    public function assertNothingDispatched(): void
    {
        if ($this->dispatched !== []) {
            throw new \Fuzeo\Queue\Exceptions\QueueException(
                count($this->dispatched) . ' job(s) were dispatched unexpectedly.'
            );
        }
    }

    public function assertDispatchedOn(string $queue, string $jobTypeOrClass): void
    {
        foreach ($this->dispatched($jobTypeOrClass) as $envelope) {
            if ($envelope->queue === $queue) {
                return;
            }
        }

        throw new \Fuzeo\Queue\Exceptions\QueueException(
            'Job ' . $jobTypeOrClass . ' was not dispatched on queue ' . $queue . '.'
        );
    }

    public function assertDispatchedForSite(string $jobTypeOrClass, int $siteId, ?int $networkId = null): void
    {
        foreach ($this->dispatched($jobTypeOrClass) as $envelope) {
            if ($envelope->context->siteId === $siteId && ($networkId === null || $envelope->context->networkId === $networkId)) {
                return;
            }
        }

        throw new \Fuzeo\Queue\Exceptions\QueueException(
            'Job ' . $jobTypeOrClass . ' was not dispatched for site ' . $siteId . '.'
        );
    }

    public function assertDispatchedFrom(string $jobTypeOrClass, string $package): void
    {
        foreach ($this->dispatched($jobTypeOrClass) as $envelope) {
            if ($envelope->origin->package === $package) {
                return;
            }
        }

        throw new \Fuzeo\Queue\Exceptions\QueueException(
            'Job ' . $jobTypeOrClass . ' was not dispatched from origin ' . $package . '.'
        );
    }

    /**
     * @param array<string, mixed> $subset
     */
    public function assertDispatchedWithPayload(string $jobTypeOrClass, array $subset): void
    {
        foreach ($this->dispatched($jobTypeOrClass) as $envelope) {
            if ($this->contains($envelope->payload, $subset)) {
                return;
            }
        }

        throw new \Fuzeo\Queue\Exceptions\QueueException(
            'Job ' . $jobTypeOrClass . ' was not dispatched with the expected payload.'
        );
    }

    private function resolveType(string $jobTypeOrClass): string
    {
        if (is_a($jobTypeOrClass, Job::class, true)) {
            return $jobTypeOrClass::type();
        }

        return JobType::normalize($jobTypeOrClass);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $subset
     */
    private function contains(array $payload, array $subset): bool
    {
        foreach ($subset as $key => $value) {
            if (!array_key_exists($key, $payload) || $payload[$key] !== $value) {
                return false;
            }
        }

        return true;
    }
}
