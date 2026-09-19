<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Schedule;

use Fuzeo\Queue\Exceptions\QueueException;

final class ScheduleExpression
{
    public function __construct(
        public readonly ScheduleExpressionType $type,
        public readonly string $value,
    ) {
        if ($this->value === '' || strlen($this->value) > 191) {
            throw new QueueException('Schedule expression value must be 1–191 characters.');
        }
    }

    public static function interval(int $seconds): self
    {
        if ($seconds < 1) {
            throw new QueueException('Interval schedules require a positive number of seconds.');
        }

        return new self(ScheduleExpressionType::Interval, (string) $seconds);
    }

    public static function dailyAt(string $time): self
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time) !== 1) {
            throw new QueueException('Daily schedules require HH:MM 24-hour time.');
        }

        return new self(ScheduleExpressionType::Daily, $time);
    }

    public static function cron(string $expression): self
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $expression) ?? '');
        CronExpression::assertValid($normalized);

        return new self(ScheduleExpressionType::Cron, $normalized);
    }

    public function seconds(): int
    {
        return $this->type === ScheduleExpressionType::Interval ? (int) $this->value : 0;
    }
}
