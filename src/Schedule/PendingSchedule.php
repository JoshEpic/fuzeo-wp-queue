<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Schedule;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Jobs\ExecutionContext;
use Fuzeo\Queue\Jobs\Job;
use Fuzeo\Queue\Jobs\QueueName;

final class PendingSchedule
{
    private ScheduleExpression $expression;

    private string $timezone = 'UTC';

    private string $queue = QueueName::DEFAULT;

    private int $priority = 0;

    private ?ExecutionContext $context = null;

    private CatchUpPolicy $catchUp = CatchUpPolicy::Skip;

    private OverlapPolicy $overlap = OverlapPolicy::Allow;

    private bool $enabled = true;

    public function __construct(
        private readonly ScheduleBook $book,
        private readonly string $name,
        private readonly Job $job,
        private readonly Clock $clock,
    ) {
        $this->expression = ScheduleExpression::interval(86400);
    }

    public function everySeconds(int $seconds): self
    {
        $clone = clone $this;
        $clone->expression = ScheduleExpression::interval($seconds);

        return $clone;
    }

    public function everyMinutes(int $minutes): self
    {
        return $this->everySeconds($minutes * 60);
    }

    public function dailyAt(string $time): self
    {
        $clone = clone $this;
        $clone->expression = ScheduleExpression::dailyAt($time);

        return $clone;
    }

    public function cron(string $expression): self
    {
        $clone = clone $this;
        $clone->expression = ScheduleExpression::cron($expression);

        return $clone;
    }

    public function timezone(string $timezone): self
    {
        (new ScheduleCalculator())->timezone($timezone);
        $clone = clone $this;
        $clone->timezone = $timezone;

        return $clone;
    }

    public function onQueue(string $queue): self
    {
        $clone = clone $this;
        $clone->queue = QueueName::normalize($queue);

        return $clone;
    }

    public function withPriority(int $priority): self
    {
        $clone = clone $this;
        $clone->priority = $priority;

        return $clone;
    }

    public function onSite(int $networkId, int $siteId): self
    {
        $clone = clone $this;
        $clone->context = ExecutionContext::site($networkId, $siteId);

        return $clone;
    }

    public function networkScoped(int $networkId): self
    {
        $clone = clone $this;
        $clone->context = ExecutionContext::network($networkId);

        return $clone;
    }

    public function catchUp(CatchUpPolicy $policy): self
    {
        $clone = clone $this;
        $clone->catchUp = $policy;

        return $clone;
    }

    public function overlap(OverlapPolicy $policy): self
    {
        $clone = clone $this;
        $clone->overlap = $policy;

        return $clone;
    }

    public function disabled(): self
    {
        $clone = clone $this;
        $clone->enabled = false;

        return $clone;
    }

    public function save(): ScheduleDefinition
    {
        return $this->book->persist($this);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function job(): Job
    {
        return $this->job;
    }

    public function expression(): ScheduleExpression
    {
        return $this->expression;
    }

    public function timezoneName(): string
    {
        return $this->timezone;
    }

    public function queue(): string
    {
        return $this->queue;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function context(): ?ExecutionContext
    {
        return $this->context;
    }

    public function catchUpPolicy(): CatchUpPolicy
    {
        return $this->catchUp;
    }

    public function overlapPolicy(): OverlapPolicy
    {
        return $this->overlap;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function clock(): Clock
    {
        return $this->clock;
    }
}
