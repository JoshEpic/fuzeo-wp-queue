<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Execution;

final class ExecutionCapabilities
{
    public function __construct(
        public readonly bool $longRunningJobs,
        public readonly string $highThroughput,
        public readonly string $lowLatency,
        public readonly string $concurrency,
        public readonly bool $rateLimits,
        public readonly string $schedulePrecision,
    ) {
    }

    public static function for(ExecutionMode $mode): self
    {
        return match ($mode) {
            ExecutionMode::Persistent => new self(true, 'high', 'high', 'full', true, 'high'),
            ExecutionMode::CronCli => new self(false, 'medium', 'low', 'limited', true, 'medium'),
            ExecutionMode::WordPressCompat => new self(false, 'low', 'low', 'very_limited', false, 'low'),
            ExecutionMode::None => new self(false, 'none', 'none', 'none', false, 'none'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'long_running_jobs' => $this->longRunningJobs,
            'high_throughput' => $this->highThroughput,
            'low_latency' => $this->lowLatency,
            'concurrency' => $this->concurrency,
            'rate_limits' => $this->rateLimits,
            'schedule_precision' => $this->schedulePrecision,
        ];
    }
}
