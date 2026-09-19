<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Jobs;

use Fuzeo\Queue\Exceptions\DuplicateJobTypeException;
use Fuzeo\Queue\Exceptions\InvalidJobTypeException;
use Fuzeo\Queue\Exceptions\UnknownJobException;

final class JobRegistry
{
    /** @var array<string, RegisteredJob> */
    private array $jobs = [];

    /**
     * @param class-string $handler
     * @param class-string<Job>|null $jobClass
     */
    public function register(
        string $type,
        int $schemaVersion,
        string $handler,
        Origin $origin,
        ?string $jobClass = null,
    ): RegisteredJob {
        $type = JobType::normalize($type);

        if ($schemaVersion < 1) {
            throw new InvalidJobTypeException('Job schema version must be a positive integer.');
        }

        if ($handler === '' || !class_exists($handler)) {
            throw new InvalidJobTypeException('Job handler class "' . $handler . '" does not exist.');
        }

        $incoming = new RegisteredJob($type, $schemaVersion, $handler, $origin, $jobClass);

        if (isset($this->jobs[$type])) {
            $existing = $this->jobs[$type];
            if (!$this->isCompatible($existing, $incoming)) {
                throw new DuplicateJobTypeException(
                    'Job type "' . $type . '" is already registered by ' . $existing->origin->package
                    . ' with handler ' . $existing->handler . ' and schema version ' . $existing->schemaVersion . '.'
                );
            }

            return $existing;
        }

        $this->jobs[$type] = $incoming;

        return $incoming;
    }

    /**
     * @param class-string<Job> $jobClass
     * @param class-string|null $handler
     */
    public function registerJob(string $jobClass, Origin $origin, ?string $handler = null): RegisteredJob
    {
        if (!is_a($jobClass, Job::class, true)) {
            throw new InvalidJobTypeException($jobClass . ' must implement ' . Job::class . '.');
        }

        return $this->register(
            $jobClass::type(),
            $jobClass::schemaVersion(),
            $handler ?? $jobClass,
            $origin,
            $jobClass,
        );
    }

    public function has(string $type): bool
    {
        return isset($this->jobs[JobType::normalize($type)]);
    }

    public function get(string $type): RegisteredJob
    {
        $type = JobType::normalize($type);
        if (!isset($this->jobs[$type])) {
            throw new UnknownJobException('Unknown job type "' . $type . '".');
        }

        return $this->jobs[$type];
    }

    /**
     * @return array<string, RegisteredJob>
     */
    public function all(): array
    {
        ksort($this->jobs);

        return $this->jobs;
    }

    private function isCompatible(RegisteredJob $existing, RegisteredJob $incoming): bool
    {
        return $existing->handler === $incoming->handler
            && $existing->schemaVersion === $incoming->schemaVersion
            && $existing->jobClass === $incoming->jobClass;
    }
}
