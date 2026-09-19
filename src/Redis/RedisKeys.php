<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Redis;

final class RedisKeys
{
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

    public function meta(): string
    {
        return $this->prefix . ':meta';
    }

    public function prefix(): string
    {
        return $this->prefix;
    }
}
