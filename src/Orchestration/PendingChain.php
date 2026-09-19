<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Orchestration;

use Fuzeo\Queue\Jobs\DispatchOptions;
use Fuzeo\Queue\Jobs\ExecutionContext;
use Fuzeo\Queue\Jobs\Job;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Jobs\QueueName;

final class PendingChain
{
    /**
     * @param list<Job> $jobs
     */
    public function __construct(
        private readonly Orchestrator $orchestrator,
        private readonly array $jobs,
        private DispatchOptions $options = new DispatchOptions(),
        private ChainFailurePolicy $policy = ChainFailurePolicy::Stop,
    ) {
        if ($this->jobs === []) {
            throw new \Fuzeo\Queue\Exceptions\QueueException('A chain must contain at least one job.');
        }
    }

    public function on(string $queue): self
    {
        $clone = clone $this;
        $clone->options = $this->options->withQueue($queue);

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

    /**
     * @param list<string> $tags
     */
    public function withTags(array $tags): self
    {
        $clone = clone $this;
        $clone->options = $this->options->withTags($tags);

        return $clone;
    }

    public function stopOnFailure(): self
    {
        $clone = clone $this;
        $clone->policy = ChainFailurePolicy::Stop;

        return $clone;
    }

    public function dispatch(): ChainRecord
    {
        $queue = $this->options->queue ?? QueueName::DEFAULT;
        $blueprints = [];
        foreach ($this->jobs as $job) {
            $blueprint = JobBlueprint::fromJob($job, $queue, 3, 60);
            $blueprints[] = new JobBlueprint(
                $blueprint->jobType,
                $blueprint->schemaVersion,
                $blueprint->payload,
                $blueprint->queue,
                $this->options->priority,
                $blueprint->maxAttempts,
                $blueprint->timeoutSeconds,
                $blueprint->metadata + $this->options->metadata,
                array_values(array_unique(array_merge($this->options->tags, $blueprint->tags))),
                $blueprint->uniqueKey,
                $blueprint->uniqueTtlSeconds,
                $blueprint->retryPolicy,
            );
        }
        $origin = $this->options->origin ?? $this->orchestrator->originFor($this->jobs[0]);
        $context = $this->options->context ?? $this->orchestrator->currentContext();

        return $this->orchestrator->dispatchChain($blueprints, $origin, $context, $this->options->metadata, $this->policy);
    }
}
