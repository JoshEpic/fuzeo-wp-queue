<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Core;

use Fuzeo\Queue\Jobs\DispatchOptions;
use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\ExecutionContext;
use Fuzeo\Queue\Jobs\Job;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Support\Delay;

final class PendingDispatch
{
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private DispatchOptions $options = new DispatchOptions(),
        private ?Job $job = null,
    ) {
    }

    public function on(string $queue): self
    {
        $clone = clone $this;
        $clone->options = $this->options->withQueue($queue);

        return $clone;
    }

    public function later(\DateTimeInterface|int|string $when): self
    {
        $at = Delay::resolve($this->dispatcher->clock(), $when);

        $clone = clone $this;
        $clone->options = $this->options->withAvailableAt($at);

        return $clone;
    }

    public function delay(int $seconds): self
    {
        if ($seconds < 0) {
            throw new \Fuzeo\Queue\Exceptions\InvalidDelayException('Delay seconds cannot be negative.');
        }
        $at = $this->dispatcher->clock()->now()->add(new \DateInterval('PT' . $seconds . 'S'));

        return $this->later($at);
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

    public function withPriority(int $priority): self
    {
        $clone = clone $this;
        $clone->options = $this->options->withPriority($priority);

        return $clone;
    }

    public function withCorrelationId(string $id): self
    {
        $clone = clone $this;
        $clone->options = $this->options->withCorrelationId($id);

        return $clone;
    }

    public function withIdempotencyKey(string $key): self
    {
        $clone = clone $this;
        $clone->options = $this->options->withIdempotencyKey($key);

        return $clone;
    }

    public function withUniqueKey(string $key): self
    {
        $clone = clone $this;
        $clone->options = $this->options->withUniqueKey($key);

        return $clone;
    }

    public function withUniqueTtl(?int $seconds): self
    {
        $clone = clone $this;
        $clone->options = $this->options->withUniqueTtl($seconds);

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

    /**
     * @param array<string, mixed> $metadata
     */
    public function withMetadata(array $metadata): self
    {
        $clone = clone $this;
        $clone->options = $this->options->withMetadata($metadata);

        return $clone;
    }

    public function withTimeout(int $seconds): self
    {
        $clone = clone $this;
        $clone->options = $this->options->withTimeout($seconds);

        return $clone;
    }

    public function requiresPersistentWorker(): self
    {
        $clone = clone $this;
        $clone->options = $this->options->requiresPersistentWorker();

        return $clone;
    }

    public function dispatch(?Job $job = null): Envelope
    {
        return $this->dispatchResult($job)->envelope;
    }

    public function dispatchResult(?Job $job = null): \Fuzeo\Queue\Jobs\DispatchResult
    {
        $job ??= $this->job;
        if ($job === null) {
            throw new \Fuzeo\Queue\Exceptions\QueueException('No job provided to dispatch.');
        }

        return $this->dispatcher->dispatch($job, $this->options);
    }

    public function withMaxAttempts(int $maxAttempts): self
    {
        $clone = clone $this;
        $clone->options = $this->options->withMaxAttempts($maxAttempts);

        return $clone;
    }

    public function withRetryPolicy(\Fuzeo\Queue\Retry\RetryPolicy $policy): self
    {
        $clone = clone $this;
        $clone->options = $this->options->withRetryPolicy($policy);

        return $clone;
    }

    public function withRateLimit(\Fuzeo\Queue\RateLimit\RateLimit $limit): self
    {
        $clone = clone $this;
        $clone->options = $this->options->withRateLimit($limit);

        return $clone;
    }
}
