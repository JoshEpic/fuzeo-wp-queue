<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

use Fuzeo\Queue\Exceptions\UnsafeMappingException;
use Fuzeo\Queue\Jobs\Job;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Jobs\QueueName;
use Fuzeo\Queue\Serialization\PayloadSerializer;

final class ActionSchedulerDescriptor
{
    /**
     * @param callable(array<string, mixed>): Job $mapper
     */
    public function __construct(
        public readonly Origin $origin,
        public readonly string $hook,
        public readonly string $jobClass,
        public readonly mixed $mapper,
        public readonly int $version = 1,
        public readonly string $group = '',
        public readonly string $queue = QueueName::DEFAULT,
        public readonly ?string $scheduleName = null,
        public readonly ?int $intervalSeconds = null,
        public readonly bool $migratePending = false,
    ) {
        if ($this->hook === '') {
            throw new UnsafeMappingException('Action Scheduler descriptor requires a hook.');
        }
        if ($this->version < 1) {
            throw new UnsafeMappingException('Descriptor version must be a positive integer.');
        }
        if (!is_callable($this->mapper)) {
            throw new UnsafeMappingException('Action Scheduler descriptor requires a callable mapper.');
        }
        if (!is_a($this->jobClass, Job::class, true)) {
            throw new UnsafeMappingException($this->jobClass . ' must implement ' . Job::class . '.');
        }
    }

    public function identity(): DescriptorIdentity
    {
        return DescriptorIdentity::actionScheduler($this->origin, $this->hook, $this->version, $this->group);
    }

    /**
     * @param array<string, mixed> $args
     */
    public function map(array $args, PayloadSerializer $serializer): Job
    {
        $job = ($this->mapper)($args);
        if (!$job instanceof Job) {
            throw new UnsafeMappingException('Mapper for ' . $this->hook . ' must return a Job.');
        }
        if ($job::class !== $this->jobClass && !is_a($job, $this->jobClass, false)) {
            throw new UnsafeMappingException('Mapper for ' . $this->hook . ' returned unexpected job type.');
        }
        $serializer->normalize($job->payload());

        return $job;
    }
}
