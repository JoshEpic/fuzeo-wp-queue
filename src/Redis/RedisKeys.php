<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Redis;

final class RedisKeys
{
    /**
     * Redis Cluster is not supported in 1.0. Keys are prefixed `fuzeo_queue:{namespace}:`.
     * Hash tags are not applied; changing the key grammar would be a breaking data-model change.
     */
    public function __construct(private readonly string $prefix)
    {
    }

    public function job(string $jobId): string
    {
        return $this->prefix . ':job:' . $jobId;
    }

    public function ready(string $queue): string
    {
        return $this->prefix . ':ready:' . $queue;
    }

    public function delayed(string $queue): string
    {
        return $this->prefix . ':delayed:' . $queue;
    }

    public function reserved(): string
    {
        return $this->prefix . ':reserved';
    }

    public function dead(): string
    {
        return $this->prefix . ':dead';
    }

    public function completed(): string
    {
        return $this->prefix . ':completed';
    }

    public function attempts(string $jobId): string
    {
        return $this->prefix . ':attempts:' . $jobId;
    }

    public function queues(): string
    {
        return $this->prefix . ':queues';
    }

    public function wakeup(string $queue): string
    {
        return $this->prefix . ':wakeup:' . $queue;
    }

    public function worker(string $workerId): string
    {
        return $this->prefix . ':worker:' . $workerId;
    }

    public function workers(): string
    {
        return $this->prefix . ':workers';
    }

    public function concurrency(string $queue): string
    {
        return $this->prefix . ':conc:' . $queue;
    }

    public function rate(string $key): string
    {
        return $this->prefix . ':rate:' . $key;
    }

    public function lock(string $name): string
    {
        return $this->prefix . ':lock:' . $name;
    }

    public function unique(string $hash): string
    {
        return $this->prefix . ':unique:' . $hash;
    }

    public function idempotency(string $hash): string
    {
        return $this->prefix . ':idemp:' . $hash;
    }

    public function idempotencyOwner(string $token): string
    {
        return $this->prefix . ':idemp-owner:' . $token;
    }

    public function schedule(string $scheduleId): string
    {
        return $this->prefix . ':schedule:' . $scheduleId;
    }

    public function schedules(): string
    {
        return $this->prefix . ':schedules';
    }

    public function scheduleDue(): string
    {
        return $this->prefix . ':schedule:due';
    }

    public function scheduleClaim(string $occurrenceId): string
    {
        return $this->prefix . ':schedule:claim:' . $occurrenceId;
    }

    public function scheduler(string $schedulerId): string
    {
        return $this->prefix . ':scheduler:' . $schedulerId;
    }

    public function schedulers(): string
    {
        return $this->prefix . ':schedulers';
    }

    public function chain(string $chainId): string
    {
        return $this->prefix . ':chain:' . $chainId;
    }

    public function chainStep(string $chainId, int $step): string
    {
        return $this->prefix . ':chain:' . $chainId . ':step:' . $step;
    }

    public function chainsIncomplete(): string
    {
        return $this->prefix . ':chains:incomplete';
    }

    public function chainsAll(): string
    {
        return $this->prefix . ':chains';
    }

    public function batch(string $batchId): string
    {
        return $this->prefix . ':batch:' . $batchId;
    }

    public function batchMember(string $batchId, int $index): string
    {
        return $this->prefix . ':batch:' . $batchId . ':member:' . $index;
    }

    public function batchesIncomplete(): string
    {
        return $this->prefix . ':batches:incomplete';
    }

    public function batchesCreating(): string
    {
        return $this->prefix . ':batches:creating';
    }

    public function batchesAll(): string
    {
        return $this->prefix . ':batches';
    }

    public function jobCancel(string $jobId): string
    {
        return $this->prefix . ':cancel:' . $jobId;
    }

    public function metric(string $resolution, int $bucket, string $metric, string $dimensionType, string $dimensionValue): string
    {
        $dim = $dimensionType . '=' . $dimensionValue;

        return $this->prefix . ':m:' . $resolution . ':' . $bucket . ':' . $metric . ':' . $dim;
    }

    public function audit(): string
    {
        return $this->prefix . ':audit';
    }

    public function deployment(): string
    {
        return $this->prefix . ':deploy';
    }

    public function meta(): string
    {
        return $this->prefix . ':meta';
    }

    public function prefix(): string
    {
        return $this->prefix;
    }
}
