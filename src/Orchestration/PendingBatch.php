<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Orchestration;

use Fuzeo\Queue\Jobs\DispatchOptions;
use Fuzeo\Queue\Jobs\ExecutionContext;
use Fuzeo\Queue\Jobs\Job;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Jobs\QueueName;

final class PendingBatch
{
    /** @var list<array{job: Job, queue: string}> */
    private array $members = [];

    private ?Job $then = null;

    private ?Job $catch = null;

    /**
     * @param list<Job> $jobs
     */
    public function __construct(
        private readonly Orchestrator $orchestrator,
        array $jobs = [],
        private DispatchOptions $options = new DispatchOptions(),
        private BatchFailurePolicy $policy = BatchFailurePolicy::CollectAll,
        private ?string $name = null,
    ) {
        foreach ($jobs as $job) {
            $this->members[] = ['job' => $job, 'queue' => $options->queue ?? QueueName::DEFAULT];
        }
    }

    public function add(Job $job, ?string $queue = null): self
    {
        $clone = clone $this;
        $clone->members[] = ['job' => $job, 'queue' => $queue ?? $this->options->queue ?? QueueName::DEFAULT];

        return $clone;
    }

    public function named(string $name): self
    {
        $clone = clone $this;
        $clone->name = $name;

        return $clone;
    }

    public function on(string $queue): self
    {
        $clone = clone $this;
        $clone->options = $this->options->withQueue($queue);
        $updated = [];
        foreach ($clone->members as $row) {
            $updated[] = ['job' => $row['job'], 'queue' => $queue];
        }
        $clone->members = $updated;

        return $clone;
    }

    public function withOrigin(Origin $origin): self
    {
        $clone = clone $this;
        $clone->options = $this->options->withOrigin($origin);

        return $clone;
    }

    public function onSite(int $networkId, int $siteId): self
    {
        $clone = clone $this;
        $clone->options = $this->options->withContext(ExecutionContext::site($networkId, $siteId));

        return $clone;
    }

    public function networkScoped(int $networkId): self
    {
        $clone = clone $this;
        $clone->options = $this->options->withContext(ExecutionContext::network($networkId));

        return $clone;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function withMetadata(array $metadata): self
    {
        $clone = clone $this;
        $clone->options = $this->options->withMetadata($metadata);

        return $clone;
    }

    public function collectAll(): self
    {
        $clone = clone $this;
        $clone->policy = BatchFailurePolicy::CollectAll;

        return $clone;
    }

    public function failFast(): self
    {
        $clone = clone $this;
        $clone->policy = BatchFailurePolicy::FailFast;

        return $clone;
    }

    public function then(Job $job): self
    {
        $clone = clone $this;
        $clone->then = $job;

        return $clone;
    }

    public function catch(Job $job): self
    {
        $clone = clone $this;
        $clone->catch = $job;

        return $clone;
    }

    public function dispatch(): BatchRecord
    {
        if ($this->members === []) {
            throw new \Fuzeo\Queue\Exceptions\QueueException('A batch must contain at least one job.');
        }
        $origin = $this->options->origin ?? $this->orchestrator->originFor($this->members[0]['job']);
        $context = $this->options->context ?? $this->orchestrator->currentContext();
        $blueprints = [];
        foreach ($this->members as $row) {
            $blueprints[] = JobBlueprint::fromJob($row['job'], $row['queue'], 3, 60);
        }
        $queue = $this->options->queue ?? QueueName::DEFAULT;
        $then = $this->then !== null ? JobBlueprint::fromJob($this->then, $queue, 3, 60) : null;
        $catch = $this->catch !== null ? JobBlueprint::fromJob($this->catch, $queue, 3, 60) : null;

        return $this->orchestrator->dispatchBatch(
            $blueprints,
            $origin,
            $context,
            $this->name,
            $this->options->metadata,
            $this->policy,
            $then,
            $catch,
        );
    }
}
