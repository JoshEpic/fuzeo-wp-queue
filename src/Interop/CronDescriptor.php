<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

use Fuzeo\Queue\Exceptions\UnsafeMappingException;
use Fuzeo\Queue\Jobs\Job;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Jobs\QueueName;
use Fuzeo\Queue\Serialization\PayloadSerializer;

final class CronDescriptor
{
    /**
     * @param callable(list<mixed>): Job $mapper
     */
    public function __construct(
        public readonly Origin $origin,
        public readonly string $hook,
        public readonly string $jobClass,
        public readonly mixed $mapper,
        public readonly int $version = 1,
        public readonly string|false|null $recurrence = null,
        public readonly ?int $intervalSeconds = null,
        public readonly string $queue = QueueName::DEFAULT,
        public readonly string $timezone = 'UTC',
        public readonly ?string $scheduleName = null,
        public readonly ?string $cronExpression = null,
        public readonly ?string $dailyAt = null,
    ) {
        if ($this->hook === '') {
            throw new UnsafeMappingException('WP-Cron descriptor requires a hook.');
        }
        if ($this->version < 1) {
            throw new UnsafeMappingException('Descriptor version must be a positive integer.');
        }
        if (!is_callable($this->mapper)) {
            throw new UnsafeMappingException('WP-Cron descriptor requires a callable mapper.');
        }
        if (!is_a($this->jobClass, Job::class, true)) {
            throw new UnsafeMappingException($this->jobClass . ' must implement ' . Job::class . '.');
        }
    }

    public function identity(): DescriptorIdentity
    {
        return DescriptorIdentity::cron($this->origin, $this->hook, $this->version);
    }

    /**
     * @param list<mixed> $args
     */
    public function map(array $args, PayloadSerializer $serializer): Job
    {
        $job = ($this->mapper)($args);
        if (!$job instanceof Job) {
            throw new UnsafeMappingException('Mapper for ' . $this->hook . ' must return a Job.');
        }
        $serializer->normalize($job->payload());

        return $job;
    }

    public function destinationName(): string
    {
        if ($this->scheduleName !== null && $this->scheduleName !== '') {
            return $this->scheduleName;
        }

        return substr(preg_replace('/[^a-z0-9_-]+/i', '-', $this->hook) ?? $this->hook, 0, 64);
    }
}
